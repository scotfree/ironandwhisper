<?php
/**
 * The bots, and the solo game they make possible.
 *
 * These also serve as a check on the port that unit tests cannot give: two bots
 * playing each other exercise every rule in combination, hundreds of times, and
 * the invariants at the end are the same ones sim/test_engine.py asserts.
 * tests/selfplay.php runs the same thing at volume and reports the win rate,
 * which should sit near the simulator's.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Bots;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;
use Bga\Games\IronAndWhisper\Scenario;
use Bga\Games\IronAndWhisper\States\EndScore;

// -- what the Empire bot is allowed to think --------------------------------

function test_the_empire_bot_reads_only_what_is_face_up(): void
{
    $scenario = Scenario::load('baseline');
    $card = fn(int $id, string $type) => [
        'id' => $id,
        'type' => $type,
        'influence' => $scenario->influenceOf($type),
        'seen' => false,
    ];

    // A town holding four influence cards, none of them turned over. The bot
    // must not be able to tell it apart from four dummies.
    $hidden = [
        'a' => ['neighbors' => [], 'troops' => 0, 'resolved' => false,
                'pile' => [$card(1, 'influence'), $card(2, 'influence'),
                           $card(3, 'influence'), $card(4, 'influence')],
                'revealed' => []],
    ];
    $bluff = $hidden;
    $bluff['a']['pile'] = [$card(1, 'dummy'), $card(2, 'dummy'),
                           $card(3, 'dummy'), $card(4, 'dummy')];

    $realEstimate = Bots::estimate(Bots::belief($scenario, $hidden), $hidden['a']);
    $bluffEstimate = Bots::estimate(Bots::belief($scenario, $bluff), $bluff['a']);

    assertSame(
        $realEstimate,
        $bluffEstimate,
        'a face-down pile of gold and a face-down pile of nothing must look identical',
    );
}

function test_turning_cards_over_moves_the_estimate(): void
{
    $scenario = Scenario::load('baseline');
    $card = fn(int $id, string $type) => [
        'id' => $id, 'type' => $type, 'influence' => $scenario->influenceOf($type), 'seen' => true,
    ];

    $town = ['neighbors' => [], 'troops' => 0, 'resolved' => false, 'pile' => [], 'revealed' => []];

    $unknown = $town;
    $unknown['pile'] = [$card(1, 'influence'), $card(2, 'influence')];

    $read = $town;
    $read['revealed'] = [$card(1, 'influence'), $card(2, 'influence')];

    $scenarioTowns = ['a' => $read];
    assertSame(
        2.0,
        Bots::estimate(Bots::belief($scenario, $scenarioTowns), $read),
        'two influence cards face up are worth exactly two',
    );
    assertTrue(
        Bots::estimate(Bots::belief($scenario, ['a' => $unknown]), $unknown) < 2.0,
        'the same two face down are only worth the deck average',
    );
}

// -- solo -------------------------------------------------------------------

function test_a_solo_game_gives_the_bot_the_other_side(): void
{
    $game = newSoloGame(Game::SIDES_FIRST_IS_EMPIRE);

    assertTrue($game->isSolo());
    assertSame(Rules::EMPIRE, $game->sideForPlayer(P_ONE));
    assertSame(Rules::INSURGENCY, $game->sideForPlayer(Game::BOT_PLAYER_ID));
    assertSame(Game::BOT_PLAYER_ID, $game->playerIdForSide(Rules::INSURGENCY));

    $insurgencySolo = newSoloGame(Game::SIDES_FIRST_IS_INSURGENCY);
    assertSame(Rules::INSURGENCY, $insurgencySolo->sideForPlayer(P_ONE));
    assertSame(Rules::EMPIRE, $insurgencySolo->sideForPlayer(Game::BOT_PLAYER_ID));
}

function test_the_bot_takes_its_turn_before_the_human_is_asked(): void
{
    // The human is the Empire, so the Insurgency bot opens. By the time the
    // human is activated the cards are already down.
    $game = newSoloGame(Game::SIDES_FIRST_IS_EMPIRE);
    $next = enterNextTurn($game);

    assertSame(EmpireTurnClass(), $next, 'the human is asked for the Empire turn');
    assertSame(P_ONE, $game->gamestate->activePlayerId, 'and it is the human who is active');

    $placed = 0;
    foreach ($game->board->towns() as $town) {
        $placed += Rules::townCardCount($town);
    }
    assertSame(5, $placed, 'the bot placed its whole hand first');
}

function EmpireTurnClass(): string
{
    return \Bga\Games\IronAndWhisper\States\EmpireTurn::class;
}

function test_the_bot_scores_without_a_player_row(): void
{
    // The bot has no row in `player`, so its points go to a global. If that
    // were wrong, scoring would either vanish or blow up on a missing row.
    $game = newSoloGame(Game::SIDES_FIRST_IS_INSURGENCY, seed: 4);
    playSoloGame($game);

    $total = $game->botScore() + $game->bga->playerScore->get(P_ONE);

    $expected = 0;
    foreach ($game->board->towns() as $town) {
        $expected += $town['winner'] === Rules::EMPIRE
            ? $town['resolvedInfluence']
            : $town['resolvedStrength'];
    }
    assertSame($expected, $total, 'every point is banked by somebody');
    assertTrue($game->botScore() > 0, 'and the bot managed to score some of them');
}

/** Drive a solo game to the end, playing the human side with the same bot. */
function playSoloGame(Game $game): void
{
    for ($guard = 0; $guard < 200; $guard++) {
        $next = enterNextTurn($game);
        if ($next === EndScore::class) {
            return;
        }
        // The human seat is played by the bot too, so the test needs no policy
        // of its own and exercises both bots.
        $game->playBotTurn($game->toMove());
    }
    throw new AssertionFailed('solo game failed to terminate');
}

// -- bots against each other ------------------------------------------------

function test_bots_play_whole_games_without_breaking_a_rule(): void
{
    for ($seed = 1; $seed <= 20; $seed++) {
        $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: $seed);

        for ($guard = 0; $guard < 200; $guard++) {
            $next = enterNextTurn($game);
            if ($next === EndScore::class) {
                break;
            }
            $game->playBotTurn($game->toMove());
        }

        $cards = 0;
        $influence = 0;
        foreach ($game->board->towns() as $townId => $town) {
            assertTrue($town['resolved'], "seed {$seed}: {$townId} left unresolved");
            $cards += Rules::townCardCount($town);
            $influence += $town['resolvedInfluence'];
        }

        assertSame(60, $cards, "seed {$seed}: the whole deck should be on the board");
        assertSame(36, $influence, "seed {$seed}: all influence accounted for");
        assertSame(13, $game->round(), "seed {$seed}: the deck is an exact clock");
    }
}
