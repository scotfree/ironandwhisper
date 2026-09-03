<?php
/**
 * The game as the server actually runs it: setup, the two turn states, the
 * hidden-information boundary, and a full game end to end.
 *
 * These run against the real dbmodel.sql through SQLite, so a schema change
 * that breaks a query breaks a test here rather than on the Studio.
 */
declare(strict_types=1);

use Bga\GameFramework\UserException;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;
use Bga\Games\IronAndWhisper\States\EmpireTurn;
use Bga\Games\IronAndWhisper\States\EndScore;
use Bga\Games\IronAndWhisper\States\InsurgencyTurn;

// -- setup ------------------------------------------------------------------

function test_setup_builds_the_map_the_deck_and_the_garrison(): void
{
    $game = newGame();
    $towns = $game->board->towns();

    assertSame(12, count($towns), 'grid12 has twelve towns');
    assertSame(60, $game->board->deckCount(), '36 influence + 24 dummy');
    assertSame(3, $towns['everlan']['troops'], 'empire_start garrisons Everlan');
    assertSame(0, $towns['ashford']['troops']);
    assertSame(0, count($game->board->hand()), 'the hand is drawn in NextTurn, not at setup');
    assertSame(Rules::INSURGENCY, $game->toMove(), 'the scenario says the Insurgency opens');
}

function test_the_side_assignment_option_decides_who_is_who(): void
{
    $empireFirst = newGame(Game::SIDES_FIRST_IS_EMPIRE);
    assertSame(Rules::EMPIRE, $empireFirst->sideForPlayer(P_ONE));
    assertSame(Rules::INSURGENCY, $empireFirst->sideForPlayer(P_TWO));

    $insurgencyFirst = newGame(Game::SIDES_FIRST_IS_INSURGENCY);
    assertSame(Rules::INSURGENCY, $insurgencyFirst->sideForPlayer(P_ONE));
    assertSame(Rules::EMPIRE, $insurgencyFirst->sideForPlayer(P_TWO));
}

function test_the_opening_turn_draws_a_full_hand_for_the_insurgency(): void
{
    $game = newGame();
    $next = enterNextTurn($game);

    assertSame(InsurgencyTurn::class, $next);
    assertSame(5, count($game->board->hand()), 'hand_size cards');
    assertSame(55, $game->board->deckCount());
    assertSame(
        $game->playerIdForSide(Rules::INSURGENCY),
        $game->gamestate->activePlayerId,
        'seat order does not decide who starts; the scenario does',
    );
}

// -- the Insurgency turn ----------------------------------------------------

function test_the_insurgency_must_empty_its_hand(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);

    assertThrows(
        UserException::class,
        fn() => insurgencyTurn($game)->actCommitTurn(['ashford' => array_slice($hand, 0, 3)], null, $insurgency),
        'Decision 6: the whole hand goes out every turn',
    );
}

function test_placed_cards_land_on_top_in_the_order_given(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);

    insurgencyTurn($game)->actCommitTurn(
        ['ashford' => array_slice($hand, 0, 2), 'belmar' => array_slice($hand, 2)],
        null,
        $insurgency,
    );

    $towns = $game->board->towns();
    assertSame(
        [$hand[1], $hand[0]],
        array_column($towns['ashford']['pile'], 'id'),
        'cards go on one at a time, so the last one given is on top',
    );
    assertSame(3, count($towns['belmar']['pile']));
    assertSame(0, count($game->board->hand()), 'the hand is empty afterwards');
    assertSame(Rules::EMPIRE, $game->toMove());
}

function test_the_insurgency_may_resolve_a_town_it_seeded_this_turn(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);

    // Ashford was empty a moment ago; placement lands before the check.
    insurgencyTurn($game)->actCommitTurn(['ashford' => $hand], 'ashford', $insurgency);

    $towns = $game->board->towns();
    assertTrue($towns['ashford']['resolved'], 'the town it just seeded is a legal target');
    assertSame(Rules::INSURGENCY, $towns['ashford']['winner'], 'undefended, so any influence takes it');
}

function test_the_insurgency_cannot_resolve_a_town_it_is_not_in(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);

    assertThrows(
        UserException::class,
        fn() => insurgencyTurn($game)->actCommitTurn(['ashford' => $hand], 'coldwater', $insurgency),
        'Decision 5: presence is required to declare',
    );
}

// -- the Empire turn --------------------------------------------------------

function test_the_empire_generates_moves_and_looks(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    // Seed Everlan, where the Empire's three troops stand.
    insurgencyTurn($game)->actCommitTurn(['everlan' => $hand], null, $insurgency);
    enterNextTurn($game);

    $before = array_column($game->board->towns()['everlan']['pile'], 'id');
    $game->bga->notify->clear();

    empireTurn($game)->actCommitTurn('everlan', [['from' => 'everlan', 'to' => 'belmar', 'count' => 1]], null, $empire);

    $towns = $game->board->towns();
    assertSame(3, $towns['everlan']['troops'], '3 + 1 raised - 1 marched away');
    assertSame(1, $towns['belmar']['troops']);

    $after = array_column($towns['everlan']['pile'], 'id');
    assertSame(
        array_merge(array_slice($before, 3), array_slice($before, 0, 3)),
        $after,
        'three troops stayed put, so three cards cycled to the bottom',
    );
}

function test_only_the_empire_is_told_what_it_saw(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['everlan' => $hand], null, $insurgency);
    enterNextTurn($game);
    $game->bga->notify->clear();

    empireTurn($game)->actCommitTurn(null, [], null, $empire);

    $private = $game->bga->notify->of('peekResult');
    assertSame(1, count($private), 'the peek result goes out exactly once');
    assertSame($empire, $private[0]['player'], 'and only to the Empire');

    $public = $game->bga->notify->of('pilesRotated');
    assertSame(['everlan' => 3], $public[0]['args']['counts'], 'everyone learns how many cards moved');

    foreach ($game->bga->notify->sent as $notification) {
        if ($notification['scope'] === 'all') {
            assertFalse(
                array_key_exists('seen', $notification['args']),
                "public notification {$notification['name']} must not carry peeked cards",
            );
        }
    }
}

function test_a_troop_that_marched_in_does_not_look(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['belmar' => $hand], null, $insurgency);
    enterNextTurn($game);
    $game->bga->notify->clear();

    // All three troops march from Everlan into the seeded town.
    empireTurn($game)->actCommitTurn(null, [['from' => 'everlan', 'to' => 'belmar', 'count' => 3]], null, $empire);

    assertSame([], $game->bga->notify->of('peekResult'), 'they arrived, so they saw nothing');
}

function test_the_empire_cannot_generate_where_it_has_no_presence(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    insurgencyTurn($game)->actCommitTurn(['ashford' => $hand], null, $game->playerIdForSide(Rules::INSURGENCY));
    enterNextTurn($game);

    assertThrows(
        UserException::class,
        fn() => empireTurn($game)->actCommitTurn('ashford', [], null, $game->playerIdForSide(Rules::EMPIRE)),
    );
}

function test_resolution_spends_the_troops_committed_to_it(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['everlan' => array_slice($hand, 0, 2), 'belmar' => array_slice($hand, 2)], null, $insurgency);
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn(null, [], 'everlan', $empire);

    $towns = $game->board->towns();
    assertTrue($towns['everlan']['resolved']);
    assertSame(0, $towns['everlan']['troops'], 'Decision 3: the garrison is removed from play');
    assertSame(9, $towns['everlan']['resolvedStrength'], 'three infantry at strength 3');
    assertSame(Rules::EMPIRE, $towns['everlan']['winner']);
    assertSame(
        $towns['everlan']['resolvedInfluence'],
        $game->bga->playerScore->get($empire),
        'the Empire banks the influence it suppressed, nothing more',
    );
}

// -- what each side may see -------------------------------------------------

function test_the_empire_sees_pile_heights_but_not_faces(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['ashford' => $hand], null, $insurgency);

    $empireView = datasFor($game, $empire);
    $insurgencyView = datasFor($game, $insurgency);

    assertSame(5, $empireView['towns']['ashford']['pileSize'], 'heights are public');
    foreach ($empireView['towns']['ashford']['pile'] as $card) {
        assertSame(null, $card['type'], 'but no unpeeked card shows its face');
    }
    assertSame(null, $empireView['hand'], 'and the hand is not the Empire\'s business');

    foreach ($insurgencyView['towns']['ashford']['pile'] as $card) {
        assertTrue($card['type'] !== null, 'the Insurgency placed them, so it sees them all');
    }
}

function test_the_empire_keeps_what_it_has_peeked_at(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['everlan' => $hand], null, $insurgency);
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn(null, [], null, $empire);

    $view = datasFor($game, $empire);
    $known = array_filter($view['towns']['everlan']['pile'], fn(array $card) => $card['type'] !== null);

    assertSame(3, count($known), 'three stationary troops read three cards');
}

function test_a_resolved_pile_is_face_up_to_both_players(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['everlan' => $hand], 'everlan', $insurgency);

    $view = datasFor($game, $empire);
    foreach ($view['towns']['everlan']['pile'] as $card) {
        assertTrue($card['type'] !== null, 'Decision 9: resolution makes the deck countable');
    }
}

// -- a whole game -----------------------------------------------------------

/**
 * Play a complete game with deliberately simple policies. The point is not good
 * play — it is that the rules never deadlock and the bookkeeping adds up.
 */
function playFullGame(Game $game): int
{
    $turns = 0;
    while (true) {
        if (++$turns > 500) {
            throw new AssertionFailed('game failed to terminate; check the clock rules');
        }

        $next = enterNextTurn($game);
        if ($next === EndScore::class) {
            return $turns;
        }

        $towns = $game->board->towns();
        if ($next === InsurgencyTurn::class) {
            $open = Rules::unresolvedTownIds($towns);
            $placements = [];
            foreach (array_values($game->board->handCardIds()) as $index => $cardId) {
                $placements[$open[$index % count($open)]][] = $cardId;
            }
            insurgencyTurn($game)->actCommitTurn($placements, null, $game->playerIdForSide(Rules::INSURGENCY));
            continue;
        }

        assertSame(EmpireTurn::class, $next);
        $generateAt = Rules::legalGenerationTowns($towns)[0];
        $resolvable = Rules::legalResolutions($towns, Rules::EMPIRE);
        // Resolve occasionally: often enough to exercise consumption, rarely
        // enough that the board outlasts the deck.
        $resolve = ($turns % 7 === 0 && $resolvable) ? $resolvable[0] : null;
        empireTurn($game)->actCommitTurn($generateAt, [], $resolve, $game->playerIdForSide(Rules::EMPIRE));
    }
}

function test_a_full_game_terminates_and_resolves_every_town(): void
{
    $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: 7);
    playFullGame($game);

    $towns = $game->board->towns();
    foreach ($towns as $townId => $town) {
        assertTrue($town['resolved'], "{$townId} was left unresolved");
    }
    assertSame(13, $game->round(), 'the deck is an exact clock: twelve Insurgency turns');
    assertSame(0, $game->board->deckCount());
    assertSame(0, count($game->board->hand()));
}

function test_every_card_reaches_a_town_and_the_influence_adds_up(): void
{
    $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: 11);
    playFullGame($game);

    $cards = 0;
    $influence = 0;
    foreach ($game->board->towns() as $town) {
        $cards += count($town['pile']);
        $influence += $town['resolvedInfluence'];
    }

    assertSame(60, $cards, 'the whole deck ends up on the board');
    assertSame(36, $influence, 'and all of its influence is accounted for');
}

function test_scoring_conserves_what_was_actually_committed(): void
{
    // Total points must equal the sum of losing-side commitments, nothing more.
    $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: 3);
    playFullGame($game);

    $expected = 0;
    foreach ($game->board->towns() as $town) {
        $expected += $town['winner'] === Rules::EMPIRE
            ? $town['resolvedInfluence']
            : $town['resolvedStrength'];
    }

    $scored = $game->bga->playerScore->get($game->playerIdForSide(Rules::EMPIRE))
        + $game->bga->playerScore->get($game->playerIdForSide(Rules::INSURGENCY));

    assertSame($expected, $scored);
}

function test_no_public_notification_ever_carries_a_hidden_card(): void
{
    $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: 5);
    playFullGame($game);

    foreach ($game->bga->notify->sent as $notification) {
        if ($notification['scope'] !== 'all') {
            continue;
        }
        foreach (['seen', 'hand'] as $key) {
            assertFalse(
                array_key_exists($key, $notification['args']),
                "public notification {$notification['name']} leaks '{$key}'",
            );
        }

        // Placements are announced publicly, but as card ids only: where a card
        // sits is derivable from pile heights, what it is is not.
        foreach ($notification['args']['cards'] ?? [] as $cardIds) {
            foreach ($cardIds as $cardId) {
                assertTrue(is_int($cardId), 'public placements must carry ids, never cards');
            }
        }
    }
}

// -- absent players ---------------------------------------------------------

function test_a_zombie_insurgency_still_empties_its_hand(): void
{
    // The deck is the clock. An Insurgency that stopped placing would stop the
    // game, so the zombie turn has to be a real turn.
    $game = newGame();
    enterNextTurn($game);

    insurgencyTurn($game)->zombie($game->playerIdForSide(Rules::INSURGENCY));

    assertSame(0, count($game->board->hand()));
    assertSame(Rules::EMPIRE, $game->toMove());
}

function test_a_zombie_empire_stands_still(): void
{
    $game = newGame();
    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(
        ['everlan' => $game->board->handCardIds()],
        null,
        $game->playerIdForSide(Rules::INSURGENCY),
    );
    enterNextTurn($game);

    empireTurn($game)->zombie($game->playerIdForSide(Rules::EMPIRE));

    $towns = $game->board->towns();
    assertSame(3, $towns['everlan']['troops'], 'nothing raised, nothing moved');
    assertSame(2, $game->round(), 'but the turn passed and the clock advanced');
}

function test_an_empty_string_means_no_resolution(): void
{
    // The client cannot send null through a BGA action parameter, so it sends
    // an empty string. If that ever reached the presence check it would look
    // like a request to resolve a town called "".
    $game = newGame();
    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(
        ['everlan' => $game->board->handCardIds()],
        '',
        $game->playerIdForSide(Rules::INSURGENCY),
    );
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn('', [], '', $game->playerIdForSide(Rules::EMPIRE));

    foreach ($game->board->towns() as $town) {
        assertFalse($town['resolved'], 'nothing should have resolved');
    }
    assertSame(3, $game->board->towns()['everlan']['troops'], 'and nothing should have been raised');
}

function test_a_spectator_sees_only_what_has_been_resolved(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();

    insurgencyTurn($game)->actCommitTurn(
        ['ashford' => array_slice($hand, 0, 4), 'belmar' => array_slice($hand, 4)],
        'ashford',
        $game->playerIdForSide(Rules::INSURGENCY),
    );

    // Somebody who is not at the table at all.
    $view = datasFor($game, 999999);

    assertSame(null, $view['hand'], 'a spectator is not dealt into anything');
    foreach ($view['towns']['ashford']['pile'] as $card) {
        assertTrue($card['type'] !== null, 'a resolved pile is face up to the room');
    }
    foreach ($view['towns']['belmar']['pile'] as $card) {
        assertSame(null, $card['type'], 'everything else stays face down');
    }
}

function test_the_payload_says_which_side_it_was_built_for(): void
{
    // The client must not work this out from a player id. It did, once, and an
    // Insurgency that failed the lookup was shown a spectator's view of its own
    // hand.
    $game = newGame(Game::SIDES_FIRST_IS_EMPIRE);

    assertSame(Rules::EMPIRE, datasFor($game, P_ONE)['you']);
    assertSame(Rules::INSURGENCY, datasFor($game, P_TWO)['you']);
    assertSame(null, datasFor($game, 999999)['you'], 'a spectator is nobody');
}
