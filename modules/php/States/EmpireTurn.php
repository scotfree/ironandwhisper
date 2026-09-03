<?php
/**
 * The Empire's turn: raise a troop, move, look, then optionally resolve.
 *
 * Ported from apply_empire_turn() in sim/engine.py. The order matters — a
 * resolution is judged against where troops *will be*, not where they were at
 * the start of the turn (Decision 4).
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper\States;

use Bga\GameFramework\Actions\Types\JsonParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\IllegalMove;
use Bga\Games\IronAndWhisper\Rules;

class EmpireTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 11,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must move'),
            descriptionMyTurn: clienttranslate('${you} may raise a troop and move, and may then resolve one town'),
        );
    }

    public function getArgs(): array
    {
        $towns = $this->game->board->towns();

        return [
            'generationTowns' => Rules::legalGenerationTowns($towns),
            'resolvable' => Rules::legalResolutions($towns, Rules::EMPIRE),
        ];
    }

    /**
     * @param array<int, array{from: string, to: string, count: int}> $moves
     */
    #[PossibleAction]
    public function actCommitTurn(
        ?string $generateAt,
        #[JsonParam] array $moves,
        ?string $resolve,
        int $activePlayerId,
    ) {
        // Empty strings mean "not doing that": see the note in InsurgencyTurn.
        $generateAt = $generateAt === '' ? null : $generateAt;
        $resolve = $resolve === '' ? null : $resolve;

        $board = $this->game->board;
        $scenario = $this->game->scenario;
        $towns = $board->towns();
        $moves = $this->normalizeMoves($moves);

        // Troop changes accumulate here and are written once, so a troop that
        // is raised and then marched in the same turn costs one update.
        $delta = [];

        // 1. Generate. Requires existing presence; a troop raised this turn may
        //    move, so generation lands before movement is validated.
        try {
            Rules::validateGeneration($towns, $generateAt);
        } catch (IllegalMove $e) {
            throw new UserException($e->getMessage());
        }
        if ($generateAt !== null) {
            $delta[$generateAt] = ($delta[$generateAt] ?? 0) + $scenario->generationRate;
            $towns[$generateAt]['troops'] += $scenario->generationRate;
        }

        // 2. Move. Simultaneous: everyone leaves, then everyone arrives.
        try {
            $plan = Rules::planMoves($towns, $moves);
        } catch (IllegalMove $e) {
            throw new UserException($e->getMessage());
        }
        foreach ($plan['departures'] as $townId => $count) {
            $delta[$townId] = ($delta[$townId] ?? 0) - $count;
            $towns[$townId]['troops'] -= $count;
        }
        foreach ($plan['arrivals'] as $townId => $count) {
            $delta[$townId] = ($delta[$townId] ?? 0) + $count;
            $towns[$townId]['troops'] += $count;
        }

        $board->adjustTroops($delta);

        $this->notify->all('empireMoved', clienttranslate('${player_name} manoeuvres'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'generateAt' => $generateAt,
            'generated' => $generateAt === null ? 0 : $scenario->generationRate,
            'moves' => $moves,
            'troops' => array_map(fn(array $town) => $town['troops'], $towns),
        ]);

        // 3. Look. Every troop that did not move peeks — no decision in it, so
        //    it happens here rather than as a prompt.
        $this->peek($towns, $plan['arrivals'], $activePlayerId);

        // 4. Optionally resolve, against the board as it now stands.
        if ($resolve !== null) {
            $towns = $board->towns();
            if (!Rules::canDeclare($towns, $resolve, Rules::EMPIRE)) {
                throw new UserException(
                    clienttranslate('You have no troops in that town, or it is already resolved')
                );
            }
            $this->game->resolveTown($resolve, Rules::EMPIRE);
        }

        $this->game->incRound();
        $this->game->setToMove(Rules::INSURGENCY);

        return NextTurn::class;
    }

    /**
     * Turn the top card of each watched pile face up.
     *
     * This is public. The cards physically sit face up beside their town, and
     * the Insurgency could always work out exactly what the Empire had seen —
     * it knows what it placed and troop positions are visible — so putting them
     * on the table costs it nothing and spares both players the bookkeeping.
     *
     * @param array<string, array> $towns    board after generation and movement
     * @param array<string, int> $arrivals
     */
    private function peek(array $towns, array $arrivals, int $activePlayerId): void
    {
        $looks = Rules::peekPlan($towns, $arrivals, $this->game->scenario->unitPeek());
        if (!$looks) {
            return;
        }

        $revealed = [];
        foreach ($looks as $townId => $lookCount) {
            $cardIds = Rules::revealFromPile($towns[$townId]['pile'], $lookCount);
            if (!$cardIds) {
                continue;
            }

            $this->game->board->reveal($cardIds);

            $byId = [];
            foreach ($towns[$townId]['pile'] as $card) {
                $byId[$card['id']] = $card;
            }
            $revealed[$townId] = array_map(
                fn(int $cardId) => [
                    'id' => $cardId,
                    'type' => $byId[$cardId]['type'],
                    'influence' => $byId[$cardId]['influence'],
                ],
                $cardIds,
            );
        }

        if (!$revealed) {
            return;
        }

        $count = array_sum(array_map('count', $revealed));
        $this->notify->all(
            'cardsRevealed',
            clienttranslate('${player_name} turns ${count} cards face up'),
            [
                'player_id' => $activePlayerId,
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
                'count' => $count,
                'revealed' => $revealed,
            ],
        );
    }

    /**
     * @param array<int, mixed> $moves
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function normalizeMoves(array $moves): array
    {
        $normalized = [];
        foreach ($moves as $move) {
            if (!is_array($move) || !isset($move['from'], $move['to'], $move['count'])) {
                throw new UserException(clienttranslate('Malformed move'));
            }
            $normalized[] = [(string) $move['from'], (string) $move['to'], (int) $move['count']];
        }
        return $normalized;
    }

    /**
     * A zombie Empire simply stands still. Standing still is always legal, and
     * the stationary troops still look — that costs the absent player nothing
     * and keeps the clock running.
     */
    public function zombie(int $playerId)
    {
        return $this->actCommitTurn(null, [], null, $playerId);
    }
}
