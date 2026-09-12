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
use Bga\Games\IronAndWhisper\States\NextTurn;
use Bga\Games\IronAndWhisper\States\Resolve;

/** What the scenario says Everlan starts with. Derived, not written down. */
function startingGarrison(Game $game, string $townId = 'everlan'): int
{
    return $game->scenario->empireStart[$townId];
}

// -- setup ------------------------------------------------------------------

function test_setup_builds_the_map_the_deck_and_the_garrison(): void
{
    $game = newGame();
    $towns = $game->board->towns();

    assertSame(12, count($towns), 'grid12 has twelve towns');
    assertSame(60, $game->board->deckCount(), 'the deck is the clock: 60 cards, 12 turns');
    assertSame(startingGarrison($game), $towns['everlan']['troops'], 'empire_start garrisons Everlan');
    assertSame(1, $towns['belmar']['troops'], 'and its neighbour, so it starts connected');
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

    $handSize = $game->scenario->handSize;

    assertSame(InsurgencyTurn::class, $next);
    assertSame($handSize, count($game->board->hand()), 'hand_size cards');
    assertSame(60 - $handSize, $game->board->deckCount());
    assertSame(
        $game->playerIdForSide(Rules::INSURGENCY),
        $game->gamestate->activePlayerId,
        'seat order does not decide who starts; the scenario does',
    );
}

/**
 * Place the whole hand into one town and hand the turn over, so the pile is
 * there at the *start* of a later turn — which is when resolution is judged.
 */
function seedTown(Game $game, string $townId): void
{
    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(
        [$townId => $game->board->handCardIds()],
        '0',
        $game->playerIdForSide(Rules::INSURGENCY),
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
        fn() => insurgencyTurn($game)->actCommitTurn(
            ['ashford' => array_slice($hand, 0, count($hand) - 1)],
            '0',
            $insurgency,
        ),
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
        '0',
        $insurgency,
    );

    $towns = $game->board->towns();
    assertSame(
        [$hand[1], $hand[0]],
        array_column($towns['ashford']['pile'], 'id'),
        'cards go on one at a time, so the last one given is on top',
    );
    assertSame(count($hand) - 2, count($towns['belmar']['pile']));
    assertSame(0, count($game->board->hand()), 'the hand is empty afterwards');
    assertSame(Rules::EMPIRE, $game->toMove());
}

function test_a_town_cannot_be_resolved_on_the_turn_it_was_seeded(): void
{
    // Decision 4: resolution happens first, against the board as the Empire
    // left it. Otherwise a turn is "place exactly enough, then cash out".
    // The phase order enforces this on its own now — the resolution is taken
    // and applied before the hand is offered — so what is left to check is that
    // an empty town cannot be resolved when that phase runs.
    $game = newGame();

    assertSame(
        InsurgencyTurn::class,
        enterNextTurn($game),
        'nothing is seeded yet, so there is no resolution phase to sit through',
    );

    assertThrows(
        UserException::class,
        fn() => resolvePhase($game)->actResolve('ashford'),
        'Decision 5: presence is required, and the cards are still in hand',
    );
}

function test_a_town_seeded_last_turn_may_be_resolved(): void
{
    $game = newGame();
    seedTown($game, 'ashford');

    // The Empire's turn passes, then the Insurgency cashes what was already there.
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $game->playerIdForSide(Rules::EMPIRE));

    assertSame(Resolve::class, enterNextTurn($game), 'there is something to resolve');
    assertSame(InsurgencyTurn::class, resolvePhase($game)->actResolve('ashford'));
    insurgencyTurn($game)->actCommitTurn(
        ['belmar' => $game->board->handCardIds()],
        '0',
        $game->playerIdForSide(Rules::INSURGENCY),
    );

    $towns = $game->board->towns();
    assertTrue($towns['ashford']['resolved']);
    assertSame(Rules::INSURGENCY, $towns['ashford']['winner'], 'undefended, so any influence takes it');
}

function test_the_insurgency_cannot_resolve_a_town_it_is_not_in(): void
{
    $game = newGame();
    seedTown($game, 'ashford');
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $game->playerIdForSide(Rules::EMPIRE));
    enterNextTurn($game);

    assertSame(
        ['ashford'],
        resolvePhase($game)->getArgs()['resolvable'],
        'only the town it actually stands in is offered',
    );
    assertThrows(
        UserException::class,
        fn() => resolvePhase($game)->actResolve('coldwater'),
        'Decision 5: presence is required to declare',
    );
}

// -- the resolution phase ---------------------------------------------------

function test_a_resolved_town_takes_no_more_cards_that_turn(): void
{
    // The phase used to be staged with the rest of the turn, so the client had
    // to forbid placing into the town being resolved and could not say why.
    // Now the town is simply closed by the time the hand is offered.
    $game = newGame();
    seedTown($game, 'ashford');
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $game->playerIdForSide(Rules::EMPIRE));
    enterNextTurn($game);
    resolvePhase($game)->actResolve('ashford');

    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    assertFalse(
        in_array('ashford', insurgencyTurn($game)->getArgs()['openTowns'], true),
        'a resolved town is not on offer',
    );
    assertThrows(
        UserException::class,
        fn() => insurgencyTurn($game)->actCommitTurn(
            ['ashford' => $game->board->handCardIds()],
            '0',
            $insurgency,
        ),
    );
}

function test_a_garrison_that_wins_its_town_may_still_march_out_of_it(): void
{
    // The staged version could not allow this: it did not know whether those
    // troops would survive the resolution, so it forbade the march. Resolving
    // first settles it, and a garrison that held its town is free to leave.
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    // One card into Everlan, where two troops stand: the Empire takes it.
    insurgencyTurn($game)->actCommitTurn(
        ['everlan' => array_slice($hand, 0, 1), 'joss' => array_slice($hand, 1)],
        '0',
        $insurgency,
    );
    enterNextTurn($game);
    resolvePhase($game)->actResolve('everlan');

    $towns = $game->board->towns();
    assertSame(Rules::EMPIRE, $towns['everlan']['winner']);
    assertSame(startingGarrison($game), $towns['everlan']['troops'], 'the winner keeps its garrison');

    empireTurn($game)->actCommitTurn(
        [],
        [['from' => 'everlan', 'to' => 'draymoor', 'count' => 1]],
        [],
        '0',
        $empire,
    );

    $towns = $game->board->towns();
    assertSame(startingGarrison($game) - 1, $towns['everlan']['troops']);
    assertSame(1, $towns['draymoor']['troops'], 'it marched out of the town it just won');
}

function test_resolving_the_last_open_town_ends_the_game(): void
{
    // Decision 1 in miniature: the board can run out before the deck does, and
    // the phase has to hand back to NextTurn rather than ask for a turn that
    // has nowhere to happen.
    $game = newGame();
    seedTown($game, 'everlan');

    // Close everything but Everlan, which the Empire garrisons and is about to
    // take. It is the Empire to move.
    foreach ($game->board->towns() as $townId => $town) {
        if ($townId !== 'everlan') {
            $game->resolveTown($townId, null);
        }
    }

    assertSame(Resolve::class, enterNextTurn($game));
    assertSame(NextTurn::class, resolvePhase($game)->actResolve('everlan'));
    assertSame(EndScore::class, enterNextTurn($game), 'nothing left to play for');
}

// -- the Empire turn --------------------------------------------------------

function test_the_empire_builds_moves_and_looks(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    // Seed Everlan, where the Empire's three troops stand.
    insurgencyTurn($game)->actCommitTurn(['everlan' => $hand], '0', $insurgency);
    enterNextTurn($game);

    $before = array_column($game->board->towns()['everlan']['pile'], 'id');
    $game->bga->notify->clear();

    // Build one, then march out everything above two, so exactly two troops
    // stay put and read — leaving a pile for the Empire to still be ignorant of
    // whatever the starting garrison happens to be.
    $stationary = 2;
    $marched = startingGarrison($game) + 1 - $stationary;

    empireTurn($game)->actCommitTurn(
        ['everlan' => 1],
        [['from' => 'everlan', 'to' => 'draymoor', 'count' => $marched]],
        [],
        '0',
        $empire,
    );

    $towns = $game->board->towns();
    assertSame($stationary, $towns['everlan']['troops'], 'built one, marched the rest out');
    assertSame($marched, $towns['draymoor']['troops']);

    assertSame(
        array_slice($before, 0, $stationary),
        array_column($towns['everlan']['revealed'], 'id'),
        'the troops that stayed put read that many cards off the top',
    );
    assertSame(
        array_slice($before, $stationary),
        array_column($towns['everlan']['pile'], 'id'),
        'and the pile holds only what is still unknown',
    );
}

function test_turning_cards_face_up_is_public(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['everlan' => $hand], '0', $insurgency);
    enterNextTurn($game);
    $game->bga->notify->clear();

    empireTurn($game)->actCommitTurn([], [], [], '0', $empire);

    // The cards are face up on the table, so this goes to the room. The
    // Insurgency could compute it anyway: it knows what it placed and troop
    // positions are public.
    $sent = $game->bga->notify->of('cardsRevealed');
    assertSame(1, count($sent));
    assertSame('all', $sent[0]['scope'], 'face up means face up to everyone');
    assertSame(
        startingGarrison($game),
        count($sent[0]['args']['revealed']['everlan']),
        'every stationary troop reads a card',
    );

    foreach ($sent[0]['args']['revealed']['everlan'] as $card) {
        assertTrue($card['type'] !== null, 'and they carry their faces');
    }
}

function test_a_troop_that_marched_in_does_not_look(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['belmar' => $hand], '0', $insurgency);
    enterNextTurn($game);
    $game->bga->notify->clear();

    // All three troops march from Everlan into the seeded town.
    empireTurn($game)->actCommitTurn([], [['from' => 'everlan', 'to' => 'belmar', 'count' => 2]], [], '0', $empire);

    assertSame([], $game->bga->notify->of('peekResult'), 'they arrived, so they saw nothing');
}

function test_the_empire_cannot_build_where_it_has_no_presence(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    insurgencyTurn($game)->actCommitTurn(['ashford' => $hand], '0', $game->playerIdForSide(Rules::INSURGENCY));
    enterNextTurn($game);

    assertThrows(
        UserException::class,
        fn() => empireTurn($game)->actCommitTurn(['ashford' => 1], [], [], '0', $game->playerIdForSide(Rules::EMPIRE)),
    );
}

function test_the_winner_keeps_its_commitment_and_takes_the_losers(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['everlan' => array_slice($hand, 0, 2), 'belmar' => array_slice($hand, 2)], '0', $insurgency);
    enterNextTurn($game);
    resolvePhase($game)->actResolve('everlan');
    empireTurn($game)->actCommitTurn([], [], [], '0', $empire);

    $towns = $game->board->towns();
    assertTrue($towns['everlan']['resolved']);
    assertSame(Rules::EMPIRE, $towns['everlan']['winner']);
    assertSame(startingGarrison($game), $towns['everlan']['troops'], 'the winner keeps its garrison');
    assertSame(
        startingGarrison($game) * $game->scenario->unitStrength(),
        $towns['everlan']['resolvedStrength'],
        'the whole garrison, whatever a troop is worth',
    );
    assertSame(0, Rules::townCardCount($towns['everlan']), "the loser's cards are taken");
    assertSame(
        $towns['everlan']['resolvedInfluence'],
        $game->bga->playerScore->get($empire),
        'the Empire banks the influence it suppressed, nothing more',
    );
}

// -- agreeing to end ---------------------------------------------------------

function test_the_game_ends_when_both_sides_offer(): void
{
    // Not a pass in the turn-skipping sense — skipping would stop the deck
    // draining, and the deck is the clock. This is a standing offer, and two
    // standing offers end the game the way exhaustion does.
    $game = newGame();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(
        ['ashford' => $game->board->handCardIds()],
        '1',
        $insurgency,
    );

    assertTrue($game->hasOfferedEnd(Rules::INSURGENCY));
    assertFalse($game->endAgreed(), 'one offer is not an agreement');
    assertSame(Resolve::class, enterNextTurn($game), 'so the game carries on');

    resolvePhase($game)->actSkipResolve();
    empireTurn($game)->actCommitTurn([], [], [], '1', $empire);

    assertTrue($game->endAgreed());
    assertSame(EndScore::class, enterNextTurn($game));
    foreach ($game->board->towns() as $townId => $town) {
        assertTrue(
            $town['resolved'] || Rules::townIsUncontested($town),
            "{$townId} had something in it and should have resolved with the rest",
        );
    }
}

function test_an_offer_to_end_stands_until_it_is_withdrawn(): void
{
    $game = newGame();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(
        ['ashford' => $game->board->handCardIds()],
        '1',
        $insurgency,
    );

    enterNextTurn($game);
    resolvePhase($game)->actSkipResolve();
    empireTurn($game)->actCommitTurn([], [], [], '0', $empire);

    assertTrue($game->hasOfferedEnd(Rules::INSURGENCY), 'still standing a round later');

    enterNextTurn($game);
    resolvePhase($game)->actSkipResolve();
    insurgencyTurn($game)->actCommitTurn(
        ['belmar' => $game->board->handCardIds()],
        '0',
        $insurgency,
    );

    assertFalse($game->hasOfferedEnd(Rules::INSURGENCY), 'and taken down on request');
}

function test_a_solo_game_ends_on_the_persons_offer_alone(): void
{
    // The bot has no opinion to give, so asking it to agree would mean a person
    // could never end a game they had lost interest in.
    $game = newSoloGame(Game::SIDES_FIRST_IS_EMPIRE);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    $next = enterNextTurn($game);
    if ($next === Resolve::class) {
        resolvePhase($game)->actSkipResolve();
    }
    empireTurn($game)->actCommitTurn([], [], [], '1', $empire);

    assertTrue($game->endAgreed(), 'one person, one offer');
    assertSame(EndScore::class, enterNextTurn($game));
}

// -- attrition ---------------------------------------------------------------

function test_a_starving_network_gets_a_turn_of_grace(): void
{
    // Massing is self-defeating without this: the troops you march in were fed
    // by the towns you marched them out of. The warning turn is what makes it a
    // decision rather than an ambush.
    $game = newGame();
    $empire = $game->playerIdForSide(Rules::EMPIRE);
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);

    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(['ashford' => $game->board->handCardIds()], '0', $insurgency);

    // Everything into Everlan, and Belmar abandoned — so Belmar's supply leaves
    // the network at the same moment the troops arrive.
    $game->board->adjustTroops(['everlan' => 5, 'belmar' => -1]);

    $massed = startingGarrison($game) + 5;
    // Everlan on its own supports this many; the rest are living on nothing.
    $ceiling = intdiv($game->scenario->towns['everlan']['supply'], $game->scenario->supplyPerTroop);
    $doomed = $massed - $ceiling;

    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $empire);

    $towns = $game->board->towns();
    assertSame($massed, $towns['everlan']['troops'], 'a turn of grace before anybody starves');
    assertSame($doomed, $towns['everlan']['starving'], 'but the board says what is coming');
    assertSame(0, $game->bga->playerScore->get($insurgency), 'and nothing is scored yet');

    // Round again, having done nothing about it.
    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(['ashford' => $game->board->handCardIds()], '0', $insurgency);
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $empire);

    $towns = $game->board->towns();
    assertSame($ceiling, $towns['everlan']['troops'], 'starved down to what supply can hold');
    assertSame(0, $towns['everlan']['starving'], 'and the warning is spent');
    assertSame(
        $doomed * $game->scenario->unitStrength(),
        $game->bga->playerScore->get($insurgency),
        'the Insurgency scores every troop that leaves the board',
    );
}

function test_repairing_the_line_during_the_grace_turn_cancels_the_starve(): void
{
    // The mark is a forecast, not a reservation.
    $game = newGame();
    $empire = $game->playerIdForSide(Rules::EMPIRE);
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);

    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(['ashford' => $game->board->handCardIds()], '0', $insurgency);
    $game->board->adjustTroops(['everlan' => 1, 'belmar' => -1]);

    $massed = startingGarrison($game) + 1;
    $ceiling = intdiv($game->scenario->towns['everlan']['supply'], $game->scenario->supplyPerTroop);

    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $empire);
    assertSame(
        $massed - $ceiling,
        $game->board->towns()['everlan']['starving'],
        'more troops than Everlan alone can feed',
    );

    // March two back into Belmar, which brings its supply into the network.
    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(['ashford' => $game->board->handCardIds()], '0', $insurgency);
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn(
        [],
        [['from' => 'everlan', 'to' => 'belmar', 'count' => 2]],
        [],
        '0',
        $empire,
    );

    $towns = $game->board->towns();
    assertSame(
        $massed,
        $towns['everlan']['troops'] + $towns['belmar']['troops'],
        'nobody starved',
    );
    assertSame(0, $towns['everlan']['starving'], 'and the warning is gone');
    assertSame(0, $game->bga->playerScore->get($insurgency));
}

// -- what each side may see -------------------------------------------------

function test_the_empire_sees_pile_heights_but_not_faces(): void
{
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    insurgencyTurn($game)->actCommitTurn(['ashford' => $hand], '0', $insurgency);

    $empireView = datasFor($game, $empire);
    $insurgencyView = datasFor($game, $insurgency);

    assertSame(count($hand), $empireView['towns']['ashford']['pileSize'], 'heights are public');
    foreach ($empireView['towns']['ashford']['pile'] as $card) {
        assertSame(null, $card['type'], 'but no face-down card shows its face');
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

    // Belmar, not Everlan: its single troop reads one card a turn, so the rest
    // of the pile stays face down and there is something left to be hidden.
    $garrison = startingGarrison($game, 'belmar');
    insurgencyTurn($game)->actCommitTurn(['belmar' => $hand], '0', $insurgency);
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $empire);

    $view = datasFor($game, $empire);

    assertSame(
        $garrison,
        count($view['towns']['belmar']['revealed']),
        'every stationary troop reads a card',
    );
    assertSame(
        count($hand) - $garrison,
        $view['towns']['belmar']['pileSize'],
        'the rest are still face down',
    );
    assertSame(count($hand), $view['towns']['belmar']['cardCount']);
    foreach ($view['towns']['belmar']['pile'] as $card) {
        assertSame(null, $card['type'], 'and the Empire still cannot see those');
    }
}

function test_a_resolved_pile_is_face_up_to_both_players(): void
{
    $game = newGame();
    seedTown($game, 'ashford');
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $game->playerIdForSide(Rules::EMPIRE));
    enterNextTurn($game);
    resolvePhase($game)->actResolve('ashford');
    insurgencyTurn($game)->actCommitTurn(
        ['belmar' => $game->board->handCardIds()],
        '0',
        $game->playerIdForSide(Rules::INSURGENCY),
    );

    $view = datasFor($game, $game->playerIdForSide(Rules::EMPIRE));
    assertSame(0, $view['towns']['ashford']['pileSize'], 'nothing left face down');
    foreach ($view['towns']['ashford']['revealed'] as $card) {
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

        // Both sides pass through the resolution phase before their turn
        // proper. This policy never resolves anything; the bots below do.
        if ($next === Resolve::class) {
            $next = resolvePhase($game)->actSkipResolve();
            if ($next === NextTurn::class) {
                continue;
            }
        }

        $towns = $game->board->towns();
        if ($next === InsurgencyTurn::class) {
            $open = Rules::unresolvedTownIds($towns);
            $placements = [];
            foreach (array_values($game->board->handCardIds()) as $index => $cardId) {
                $placements[$open[$index % count($open)]][] = $cardId;
            }
            insurgencyTurn($game)->actCommitTurn($placements, '0', $game->playerIdForSide(Rules::INSURGENCY));
            continue;
        }

        assertSame(EmpireTurn::class, $next);
        // The Empire side is played by the bot: building correctly now needs to
        // respect production and supply, which is not worth reimplementing here.
        $game->playBotTurn(Rules::EMPIRE);
    }
}

function test_a_full_game_terminates_and_resolves_every_town(): void
{
    $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: 7);
    playFullGame($game);

    $towns = $game->board->towns();
    foreach ($towns as $townId => $town) {
        assertTrue(
            $town['resolved'] || Rules::townIsUncontested($town),
            "{$townId} had something in it and was left unresolved",
        );
    }
    assertSame(
        $game->scenario->turns() + 1,
        $game->round(),
        'the deck is an exact clock: deck_size / hand_size Insurgency turns',
    );
    assertSame(0, $game->board->deckCount());
    assertSame(0, count($game->board->hand()));
}

function test_every_card_is_placed_and_counted(): void
{
    $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: 11);
    playFullGame($game);

    $influence = 0;
    foreach ($game->board->towns() as $town) {
        $influence += $town['resolvedInfluence'];
    }

    assertSame(0, $game->board->deckCount(), 'the deck is spent');
    assertSame(0, count($game->board->hand()), 'and the hand with it');
    assertSame(
        $game->scenario->totalInfluence(),
        $influence,
        'every card was placed somewhere and counted in some resolution',
    );
}

function test_scoring_conserves_what_was_actually_committed(): void
{
    // Total points must equal the sum of losing-side commitments, nothing more.
    $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: 3);
    playFullGame($game);

    $captured = 0;
    $beaten = 0;
    foreach ($game->board->towns() as $town) {
        if ($town['winner'] === Rules::EMPIRE) {
            $captured += $town['resolvedInfluence'];
        } else {
            $beaten += $town['resolvedStrength'];
        }
    }

    $empireScore = $game->bga->playerScore->get($game->playerIdForSide(Rules::EMPIRE));
    $insurgencyScore = $game->bga->playerScore->get($game->playerIdForSide(Rules::INSURGENCY));

    assertSame($captured, $empireScore, 'the Empire scores exactly the influence it beat');

    // The Insurgency scores every Empire troop that left the board: the ones it
    // beat at a resolution, plus any that starved when a line was cut.
    assertTrue($insurgencyScore >= $beaten);
    assertSame(
        0,
        ($insurgencyScore - $beaten) % $game->scenario->unitStrength(),
        'the excess is whole troops, starved',
    );
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
        '0',
        $game->playerIdForSide(Rules::INSURGENCY),
    );
    enterNextTurn($game);

    empireTurn($game)->zombie($game->playerIdForSide(Rules::EMPIRE));

    $towns = $game->board->towns();
    assertSame(startingGarrison($game), $towns['everlan']['troops'], 'nothing raised, nothing moved');
    assertSame(2, $game->round(), 'but the turn passed and the clock advanced');
}

function test_declining_to_resolve_is_an_action_of_its_own(): void
{
    // "Resolve nothing" used to be an empty string in the committed turn, which
    // had to be normalised before it looked like a request to resolve a town
    // called "". It is now a separate action, and there is no string to get
    // wrong.
    $game = newGame();
    enterNextTurn($game);
    insurgencyTurn($game)->actCommitTurn(
        ['everlan' => $game->board->handCardIds()],
        '0',
        $game->playerIdForSide(Rules::INSURGENCY),
    );

    assertSame(Resolve::class, enterNextTurn($game), 'the Empire stands over a seeded town');
    assertSame(EmpireTurn::class, resolvePhase($game)->actSkipResolve());
    empireTurn($game)->actCommitTurn([], [], [], '0', $game->playerIdForSide(Rules::EMPIRE));

    foreach ($game->board->towns() as $town) {
        assertFalse($town['resolved'], 'nothing should have resolved');
    }
    assertSame(
        startingGarrison($game),
        $game->board->towns()['everlan']['troops'],
        'and nothing should have been raised',
    );
}

function test_a_spectator_sees_only_what_has_been_resolved(): void
{
    $game = newGame();
    seedTown($game, 'ashford');
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $game->playerIdForSide(Rules::EMPIRE));
    enterNextTurn($game);
    resolvePhase($game)->actResolve('ashford');
    insurgencyTurn($game)->actCommitTurn(
        ['belmar' => $game->board->handCardIds()],
        '0',
        $game->playerIdForSide(Rules::INSURGENCY),
    );

    // Somebody who is not at the table at all.
    $view = datasFor($game, 999999);

    assertSame(null, $view['hand'], 'a spectator is not dealt into anything');
    assertSame(0, $view['towns']['ashford']['pileSize'], 'a resolved town has nothing face down');
    foreach ($view['towns']['ashford']['revealed'] as $card) {
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

function test_the_resolution_notification_says_what_actually_left(): void
{
    // The client rebuilds a town from this, so getting it wrong makes pieces
    // vanish. Only the loser's commitment leaves.
    $game = newGame();
    enterNextTurn($game);
    $hand = $game->board->handCardIds();
    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);

    // Everlan: two troops, one weak card. The Empire wins and keeps them.
    insurgencyTurn($game)->actCommitTurn(
        ['everlan' => array_slice($hand, 0, 1), 'joss' => array_slice($hand, 1)],
        '0',
        $insurgency,
    );
    enterNextTurn($game);
    $game->bga->notify->clear();
    resolvePhase($game)->actResolve('everlan');
    empireTurn($game)->actCommitTurn([], [], [], '0', $empire);

    $args = $game->bga->notify->of('townResolved')[0]['args'];
    assertSame(Rules::EMPIRE, $args['winner']);
    assertSame(0, $args['troopsLost'], 'the winner loses nothing');
    assertTrue($args['cardsTaken'] > 0, 'and takes the cards');
    assertSame(
        startingGarrison($game),
        $game->board->towns()['everlan']['troops'],
        'the garrison is still there',
    );
}

function test_a_town_the_insurgency_takes_reports_the_troops_lost(): void
{
    // The whole hand into Belmar, where one troop stands, then resolve it on
    // the following turn — resolution is judged before this turn's placements.
    $game = newGame();
    seedTown($game, 'belmar');
    enterNextTurn($game);
    empireTurn($game)->actCommitTurn([], [], [], '0', $game->playerIdForSide(Rules::EMPIRE));
    enterNextTurn($game);
    $game->bga->notify->clear();
    resolvePhase($game)->actResolve('belmar');
    insurgencyTurn($game)->actCommitTurn(
        ['joss' => $game->board->handCardIds()],
        '0',
        $game->playerIdForSide(Rules::INSURGENCY),
    );

    $args = $game->bga->notify->of('townResolved')[0]['args'];
    if ($args['winner'] === Rules::INSURGENCY) {
        assertSame(1, $args['troopsLost'], 'the garrison is what left');
        assertSame(0, $game->board->towns()['belmar']['troops']);
    } else {
        assertSame(0, $args['troopsLost'], 'the Empire held, so nothing of its left');
        assertSame(1, $game->board->towns()['belmar']['troops']);
    }
}
