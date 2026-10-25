<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * IronAndWhisper implementation : © Scot Free Kennedy
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 */
/**
 * Advanced rules only (game option 105, issue #6): before anything else
 * happens, the Rebels may arrange their deck in any order, or shuffle it
 * again.
 *
 * Entered once, from Game::setupNewGame, only when advanced rules are on and
 * the Insurgency is a real person — a bot has no reason to want anything but
 * the shuffle it was already dealt, and Game::setupNewGame never returns this
 * class for one. Whatever order comes out of here is what NextTurn draws from
 * for the rest of the game; the Empire never sees it, exactly as it never
 * sees any other face-down card.
 *
 * The deck itself does not travel through getArgs(): that is broadcast to
 * every viewer of this state, and the deck is not public. It travels through
 * View::forSide instead, gated on Game::deckArrangementOpen() — the same
 * route the Insurgency's hand already takes, and the one place hidden
 * information is allowed to leave the server (CLAUDE.md, View.php).
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper\States;

use Bga\GameFramework\Actions\Types\JsonParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;

class ArrangeDeck extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 13,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} is arranging the deck'),
            descriptionMyTurn: clienttranslate('${you} may arrange the deck before play begins'),
        );
    }

    /**
     * Game::setupNewGame activates whoever is to move, which may be the
     * Empire — the scenario decides who goes first, and arranging the deck
     * is unrelated to that. This state is always the Rebels', whoever moves
     * first, so it activates them itself rather than trusting setup's choice.
     */
    public function onEnteringState()
    {
        $this->gamestate->changeActivePlayer($this->game->playerIdForSide(Rules::INSURGENCY));
    }

    public function getArgs(): array
    {
        return [];
    }

    /**
     * @param int[] $order the Rebels' chosen draw order, index 0 drawn next
     */
    #[PossibleAction]
    public function actArrangeDeck(#[JsonParam] array $order)
    {
        $this->game->arrangeDeck($order);

        return NextTurn::class;
    }

    /**
     * A zombie leaves the deck exactly as it was dealt — there is no bot
     * opinion to stand in for a real decision here, unlike every other
     * zombie turn in the game.
     */
    public function zombie(int $playerId)
    {
        $this->game->arrangeDeck($this->game->board->deckCardIds());

        return NextTurn::class;
    }
}
