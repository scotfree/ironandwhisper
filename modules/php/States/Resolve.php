<?php
/**
 * The resolution phase: the first thing either side does on its turn.
 *
 * Resolution is judged on the board as your opponent left it (Decision 4), so
 * it has to happen before anything else moves. Giving it a state of its own
 * means it happens *immediately* rather than being staged alongside the rest of
 * the turn: the cards turn over, the score moves, and the board the player then
 * plans against is the real one. Staged, the client had to guess — it could not
 * know whether a garrison would survive its own resolution, so it forbade
 * marching out of the town and placing into it, and both restrictions read as
 * arbitrary.
 *
 * Once per turn is structural here: there is one action, and it leaves.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;

class Resolve extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 12,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} may resolve a town'),
            descriptionMyTurn: clienttranslate('${you} may resolve one town, before anything else happens'),
        );
    }

    public function getArgs(): array
    {
        return [
            // The client draws the same phase for both sides but says different
            // things about it, so it is told which side is standing here.
            'side' => $this->game->toMove(),
            'resolvable' => Rules::legalResolutions(
                $this->game->board->towns(),
                $this->game->toMove(),
            ),
        ];
    }

    #[PossibleAction]
    public function actResolve(string $town)
    {
        $this->game->declareResolution($town, $this->game->toMove());

        return $this->rest();
    }

    #[PossibleAction]
    public function actSkipResolve()
    {
        return $this->rest();
    }

    /**
     * The rest of the mover's turn — or the end of the game, if that
     * resolution closed the last open town.
     */
    private function rest(): string
    {
        if (!Rules::unresolvedTownIds($this->game->board->towns())) {
            return NextTurn::class;
        }

        return $this->game->toMove() === Rules::INSURGENCY
            ? InsurgencyTurn::class
            : EmpireTurn::class;
    }

    /**
     * Resolving is optional, so a zombie simply declines. The turn state it
     * hands on to has its own zombie handler for the part that is not.
     */
    public function zombie(int $playerId)
    {
        return $this->actSkipResolve();
    }
}
