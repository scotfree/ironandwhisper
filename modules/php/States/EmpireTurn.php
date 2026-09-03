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
use Bga\Games\IronAndWhisper\Game;
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
     * Commit the whole turn at once.
     *
     * The work is in Game::applyEmpireTurn, so that a bot takes its turn
     * through the same code and the same validation as a person.
     *
     * @param array<int, array{from: string, to: string, count: int}> $moves
     */
    #[PossibleAction]
    public function actCommitTurn(
        ?string $generateAt,
        #[JsonParam] array $moves,
        ?string $resolve,
        int $activePlayerId,
    ) {
        $this->game->applyEmpireTurn($generateAt, $moves, $resolve, $activePlayerId);

        return NextTurn::class;
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
