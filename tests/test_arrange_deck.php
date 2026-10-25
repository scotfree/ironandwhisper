<?php
/**
 * Advanced rules (game option 105, issue #6): the Rebels' one-time deck
 * arrangement before play begins.
 *
 * ArrangeDeck is only ever entered from Game::setupNewGame, and only ever
 * exited by submitting an order — there is no sim/engine.py equivalent to
 * port from, because this is not a rule a bot or the simulator needs: both
 * engines have always drawn top-to-bottom from whatever the deck held, and
 * nothing here changes that once play begins. The deck's contents stay just
 * as hidden from the Empire as they always were, arranged or not.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;
use Bga\Games\IronAndWhisper\States\ArrangeDeck;
use Bga\Games\IronAndWhisper\States\InsurgencyTurn;
use Bga\Games\IronAndWhisper\States\NextTurn;
use Bga\GameFramework\UserException;

// -- whether it applies at all -----------------------------------------------

function test_advanced_rules_off_skips_straight_to_next_turn(): void
{
    [$game, $state] = newGameWithStartState(Game::ADVANCED_RULES_OFF);
    assertSame(NextTurn::class, $state);
    assertFalse($game->deckArrangementOpen());
}

function test_advanced_rules_on_with_a_human_rebel_opens_arrangement(): void
{
    [$game, $state] = newGameWithStartState(Game::ADVANCED_RULES_ON);
    assertSame(ArrangeDeck::class, $state);
    assertTrue($game->deckArrangementOpen());
}

function test_advanced_rules_on_with_a_bot_rebel_still_skips_it(): void
{
    // Solo, Empire human (SIDES_FIRST_IS_EMPIRE with one player): the bot has
    // no reason to want anything but the shuffle it was already dealt.
    [$game, $state] = newGameWithStartState(
        Game::ADVANCED_RULES_ON,
        Game::SIDES_FIRST_IS_EMPIRE,
        solo: true,
    );
    assertSame(NextTurn::class, $state);
    assertFalse($game->deckArrangementOpen());
}

// -- who it activates ---------------------------------------------------------

function test_it_activates_whoever_holds_the_rebel_side(): void
{
    [$gameEmpireFirst] = newGameWithStartState(Game::ADVANCED_RULES_ON, Game::SIDES_FIRST_IS_EMPIRE);
    (new ArrangeDeck($gameEmpireFirst))->onEnteringState();
    assertSame(
        $gameEmpireFirst->playerIdForSide(Rules::INSURGENCY),
        $gameEmpireFirst->gamestate->activePlayerId,
    );

    [$gameRebelFirst] = newGameWithStartState(Game::ADVANCED_RULES_ON, Game::SIDES_FIRST_IS_INSURGENCY);
    (new ArrangeDeck($gameRebelFirst))->onEnteringState();
    assertSame(
        $gameRebelFirst->playerIdForSide(Rules::INSURGENCY),
        $gameRebelFirst->gamestate->activePlayerId,
    );
}

// -- submitting an order ------------------------------------------------------

function test_it_rejects_an_order_that_is_not_the_deck(): void
{
    [$game] = newGameWithStartState(Game::ADVANCED_RULES_ON);
    $deck = $game->board->deckCardIds();

    assertThrows(UserException::class, fn() => arrangeDeckPhase($game)->actArrangeDeck(
        array_slice($deck, 1), // missing a card
    ));
    assertThrows(UserException::class, fn() => arrangeDeckPhase($game)->actArrangeDeck(
        [...$deck, $deck[0]], // a repeat
    ));
    assertThrows(UserException::class, fn() => arrangeDeckPhase($game)->actArrangeDeck(
        [...$deck, 999999], // a card not in the deck
    ));

    // None of those touched the deck or closed arrangement.
    assertSame($deck, $game->board->deckCardIds());
    assertTrue($game->deckArrangementOpen());
}

function test_it_sets_the_chosen_order_and_closes_arrangement(): void
{
    [$game] = newGameWithStartState(Game::ADVANCED_RULES_ON);
    $reversed = array_reverse($game->board->deckCardIds());

    $next = arrangeDeckPhase($game)->actArrangeDeck($reversed);

    assertSame(NextTurn::class, $next);
    assertSame($reversed, $game->board->deckCardIds());
    assertFalse($game->deckArrangementOpen());
}

function test_zombie_leaves_the_deck_exactly_as_dealt(): void
{
    [$game] = newGameWithStartState(Game::ADVANCED_RULES_ON);
    $dealt = $game->board->deckCardIds();

    $next = (new ArrangeDeck($game))->zombie($game->playerIdForSide(Rules::INSURGENCY));

    assertSame(NextTurn::class, $next);
    assertSame($dealt, $game->board->deckCardIds());
    assertFalse($game->deckArrangementOpen());
}

// -- who may see the deck, and for how long ----------------------------------

function test_the_deck_is_visible_only_to_the_rebel_and_only_while_open(): void
{
    [$game] = newGameWithStartState(Game::ADVANCED_RULES_ON);
    $empireId = $game->playerIdForSide(Rules::EMPIRE);
    $rebelId = $game->playerIdForSide(Rules::INSURGENCY);

    // The Empire never sees it, arranged or not — exactly as it never sees
    // any other face-down card.
    assertSame(null, datasFor($game, $empireId)['deck']);

    $rebelDeck = datasFor($game, $rebelId)['deck'];
    assertTrue($rebelDeck !== null, 'the Rebel should see the deck while arrangement is open');
    assertSame(count($game->board->deckCardIds()), count($rebelDeck));

    arrangeDeckPhase($game)->actArrangeDeck($game->board->deckCardIds());

    assertSame(null, datasFor($game, $rebelId)['deck'], 'closed once arrangement is submitted');
    assertSame(null, datasFor($game, $empireId)['deck']);
}

function test_the_deck_is_never_sent_when_advanced_rules_are_off(): void
{
    $game = newGame();
    $rebelId = $game->playerIdForSide(Rules::INSURGENCY);
    assertSame(null, datasFor($game, $rebelId)['deck']);
}

// -- the rest of the game still works afterwards -----------------------------

function test_play_continues_normally_once_the_deck_is_arranged(): void
{
    [$game] = newGameWithStartState(Game::ADVANCED_RULES_ON);
    arrangeDeckPhase($game)->actArrangeDeck(array_reverse($game->board->deckCardIds()));

    // Nothing resolvable on an empty board, so straight to the Rebels' turn —
    // the scenario's first_player, unaffected by any of this.
    assertSame(InsurgencyTurn::class, enterNextTurn($game));
}
