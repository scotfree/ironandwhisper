<?php
/**
 * The Insurgency's turn: seed the whole hand, then optionally resolve.
 *
 * Ported from apply_insurgency_turn() in sim/engine.py.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper\States;

use Bga\GameFramework\Actions\Types\JsonParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;

class InsurgencyTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must place the whole hand'),
            descriptionMyTurn: clienttranslate('${you} must place your entire hand, and may then resolve one town'),
        );
    }

    public function getArgs(): array
    {
        $towns = $this->game->board->towns();

        return [
            // Every card must be placed (Decision 6), so the only real choice
            // is how to split them across the towns still open.
            'openTowns' => Rules::unresolvedTownIds($towns),
            'resolvable' => Rules::legalResolutions($towns, Rules::INSURGENCY),
        ];
    }

    /**
     * Commit the whole turn at once: the placement is one simultaneous
     * decision, and the optional resolution is judged against the board as it
     * stands after the cards land.
     *
     * The work is in Game::applyInsurgencyTurn, so that a bot takes its turn
     * through the same code and the same validation as a person.
     *
     * @param array<string, int[]> $placements town id => card ids, in the order
     *                                         they go onto the pile (last on top)
     */
    #[PossibleAction]
    public function actCommitTurn(
        #[JsonParam] array $placements,
        ?string $resolve,
        int $activePlayerId,
    ) {
        $this->game->applyInsurgencyTurn($placements, $resolve, $activePlayerId);

        return NextTurn::class;
    }

    /**
     * A zombie Insurgency still has to empty its hand — the deck is the clock,
     * and skipping would stop it. Dumping everything into one open town is the
     * simplest legal turn.
     */
    public function zombie(int $playerId)
    {
        $towns = $this->game->board->towns();
        $open = Rules::unresolvedTownIds($towns);
        $hand = $this->game->board->handCardIds();

        if (!$open || !$hand) {
            $this->game->setToMove(Rules::EMPIRE);
            return NextTurn::class;
        }

        return $this->actCommitTurn(
            [$this->getRandomZombieChoice($open) => $hand],
            null,
            $playerId,
        );
    }
}
