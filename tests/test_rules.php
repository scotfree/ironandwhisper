<?php
/**
 * The rules, tested without a database.
 *
 * Each test is named for the design decision it pins down, mirroring
 * sim/test_engine.py. If one of these disagrees with the simulator, the PHP is
 * wrong — the simulator is the specification.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\IllegalMove;
use Bga\Games\IronAndWhisper\Rules;

const STRENGTH = 3;

function rulesCard(int $id, string $type): array
{
    return ['id' => $id, 'type' => $type, 'influence' => $type === 'influence' ? 1 : 0];
}

/** A pile written newest-first, the way it is actually stored. */
function rulesPile(array $types, int $firstId = 1): array
{
    $pile = [];
    foreach ($types as $offset => $type) {
        $pile[] = rulesCard($firstId + $offset, $type);
    }
    return $pile;
}

function rulesTown(array $overrides = []): array
{
    return array_merge(
        ['neighbors' => [], 'troops' => 0, 'resolved' => false, 'pile' => []],
        $overrides,
    );
}

/** A path graph a-b-c, the simplest thing with real adjacency. */
function rulesBoard(array $overrides = []): array
{
    $towns = [
        'a' => rulesTown(['neighbors' => ['b']]),
        'b' => rulesTown(['neighbors' => ['a', 'c']]),
        'c' => rulesTown(['neighbors' => ['b']]),
    ];
    foreach ($overrides as $townId => $override) {
        $towns[$townId] = array_merge($towns[$townId], $override);
    }
    return $towns;
}

// -- scoring ----------------------------------------------------------------

function test_empire_wins_and_scores_the_influence_it_captured(): void
{
    $towns = rulesBoard(['a' => ['troops' => 2, 'pile' => rulesPile(['influence', 'influence'])]]);
    $outcome = Rules::resolveTown($towns, 'a', STRENGTH, true);

    assertSame(Rules::EMPIRE, $outcome['winner'], '6 strength beats 2 influence');
    assertSame(2, $outcome['points'], 'the Empire banks the influence it suppressed');
}

function test_insurgency_wins_and_scores_the_strength_it_overcame(): void
{
    $towns = rulesBoard(['a' => ['troops' => 1, 'pile' => rulesPile(array_fill(0, 5, 'influence'))]]);
    $outcome = Rules::resolveTown($towns, 'a', STRENGTH, true);

    assertSame(Rules::INSURGENCY, $outcome['winner'], '5 influence beats 3 strength');
    assertSame(3, $outcome['points'], 'the Insurgency banks the strength it absorbed');
}

function test_dummies_are_worth_nothing_so_a_bluff_pays_the_empire_nothing(): void
{
    $towns = rulesBoard(['a' => ['troops' => 2, 'pile' => rulesPile(['dummy', 'dummy', 'dummy'])]]);
    $outcome = Rules::resolveTown($towns, 'a', STRENGTH, true);

    assertSame(Rules::EMPIRE, $outcome['winner']);
    assertSame(0, $outcome['points'], 'capture-only scoring: a phantom pile is worth zero');
}

function test_walkover_scores_nothing(): void
{
    $towns = rulesBoard(['a' => ['troops' => 0, 'pile' => []]]);
    $outcome = Rules::resolveTown($towns, 'a', STRENGTH, true);

    assertSame(Rules::EMPIRE, $outcome['winner'], 'the Empire wins the 0-0 tie');
    assertSame(0, $outcome['points']);
}

function test_empire_wins_ties(): void
{
    $towns = rulesBoard(['a' => ['troops' => 1, 'pile' => rulesPile(['influence', 'influence', 'influence'])]]);
    $outcome = Rules::resolveTown($towns, 'a', STRENGTH, true);

    assertSame(Rules::EMPIRE, $outcome['winner'], 'Decision 7: 3 against 3 goes to the Empire');
    assertSame(3, $outcome['points']);
}

function test_tiebreaker_is_configurable(): void
{
    $towns = rulesBoard(['a' => ['troops' => 1, 'pile' => rulesPile(['influence', 'influence', 'influence'])]]);
    $outcome = Rules::resolveTown($towns, 'a', STRENGTH, false);

    assertSame(Rules::INSURGENCY, $outcome['winner']);
    assertSame(3, $outcome['points']);
}

function test_resolved_towns_cannot_be_resolved_twice(): void
{
    $towns = rulesBoard(['a' => ['resolved' => true]]);

    assertThrows(IllegalMove::class, fn() => Rules::resolveTown($towns, 'a', STRENGTH, true));
}

// -- presence ---------------------------------------------------------------

function test_empire_cannot_resolve_a_town_it_has_no_troops_in(): void
{
    $towns = rulesBoard(['a' => ['troops' => 0, 'pile' => rulesPile(['influence'])]]);

    assertFalse(Rules::canDeclare($towns, 'a', Rules::EMPIRE), 'Decision 5');
    assertTrue(Rules::canDeclare($towns, 'a', Rules::INSURGENCY));
}

function test_insurgency_cannot_resolve_a_town_with_no_cards(): void
{
    $towns = rulesBoard(['a' => ['troops' => 2]]);

    assertFalse(Rules::canDeclare($towns, 'a', Rules::INSURGENCY));
    assertTrue(Rules::canDeclare($towns, 'a', Rules::EMPIRE));
}

function test_a_resolved_town_is_declarable_by_nobody(): void
{
    $towns = rulesBoard(['a' => ['troops' => 2, 'pile' => rulesPile(['influence']), 'resolved' => true]]);

    assertFalse(Rules::canDeclare($towns, 'a', Rules::EMPIRE));
    assertFalse(Rules::canDeclare($towns, 'a', Rules::INSURGENCY));
    assertSame([], Rules::legalResolutions($towns, Rules::EMPIRE));
}

// -- placement --------------------------------------------------------------

function test_entire_hand_must_be_placed(): void
{
    $towns = rulesBoard();

    assertThrows(
        IllegalMove::class,
        fn() => Rules::validatePlacements($towns, [1, 2, 3], ['a' => [1, 2]]),
        'Decision 6: holding a card back is not allowed',
    );
    Rules::validatePlacements($towns, [1, 2, 3], ['a' => [1, 2], 'b' => [3]]);
}

function test_a_card_cannot_be_placed_twice(): void
{
    $towns = rulesBoard();

    assertThrows(
        IllegalMove::class,
        fn() => Rules::validatePlacements($towns, [1, 2], ['a' => [1, 1], 'b' => [2]]),
    );
}

function test_cards_cannot_be_placed_in_a_resolved_town(): void
{
    $towns = rulesBoard(['a' => ['resolved' => true]]);

    assertThrows(IllegalMove::class, fn() => Rules::validatePlacements($towns, [1], ['a' => [1]]));
}

// -- generation -------------------------------------------------------------

function test_generation_requires_existing_presence(): void
{
    $towns = rulesBoard(['a' => ['troops' => 1]]);

    assertSame(['a'], Rules::legalGenerationTowns($towns));
    assertThrows(IllegalMove::class, fn() => Rules::validateGeneration($towns, 'b'));
    Rules::validateGeneration($towns, 'a');
}

function test_resolved_towns_do_not_anchor_generation(): void
{
    // The garrison was spent when the town resolved, so the Empire no longer
    // holds the place — and troops is 0 there in any case.
    $towns = rulesBoard([
        'a' => ['troops' => 0, 'resolved' => true],
        'b' => ['troops' => 2],
    ]);

    assertSame(['b'], Rules::legalGenerationTowns($towns));
}

function test_generation_falls_back_when_the_empire_is_swept_off_the_board(): void
{
    // Without this, spending your last troops ends the game early.
    $towns = rulesBoard(['a' => ['resolved' => true]]);

    assertSame(['b', 'c'], Rules::legalGenerationTowns($towns), 'any unresolved town, resolved ones excluded');
}

// -- movement ---------------------------------------------------------------

function test_movement_must_follow_an_edge(): void
{
    $towns = rulesBoard(['a' => ['troops' => 3]]);

    assertThrows(IllegalMove::class, fn() => Rules::planMoves($towns, [['a', 'c', 1]]));
    Rules::planMoves($towns, [['a', 'b', 1]]);
}

function test_cannot_move_more_troops_than_are_present(): void
{
    $towns = rulesBoard(['a' => ['troops' => 2]]);

    assertThrows(IllegalMove::class, fn() => Rules::planMoves($towns, [['a', 'b', 3]]));
    assertThrows(
        IllegalMove::class,
        fn() => Rules::planMoves($towns, [['a', 'b', 1], ['a', 'b', 2]]),
        'the limit applies across several moves out of the same town',
    );
}

function test_resolved_towns_are_passable_terrain(): void
{
    // Resolved towns are pacified, not walls: troops move in and out freely.
    $towns = rulesBoard([
        'a' => ['troops' => 2],
        'b' => ['resolved' => true],
    ]);

    $plan = Rules::planMoves($towns, [['a', 'b', 2]]);
    assertSame(['b' => 2], $plan['arrivals']);
}

function test_movement_is_simultaneous(): void
{
    // Two garrisons can swap places in one turn.
    $towns = rulesBoard(['a' => ['troops' => 2], 'b' => ['troops' => 3]]);

    $plan = Rules::planMoves($towns, [['a', 'b', 2], ['b', 'a', 3]]);
    assertSame(['a' => 2, 'b' => 3], $plan['departures']);
    assertSame(['b' => 2, 'a' => 3], $plan['arrivals']);
}

// -- looking ----------------------------------------------------------------

function test_a_stationary_troop_reads_one_card_per_turn(): void
{
    $towns = rulesBoard(['a' => ['troops' => 1, 'pile' => rulesPile(['influence', 'dummy', 'dummy'])]]);

    assertSame(['a' => 1], Rules::peekPlan($towns, [], 1));
}

function test_peeks_stack_across_stationary_troops(): void
{
    $towns = rulesBoard(['a' => ['troops' => 3, 'pile' => rulesPile(['influence', 'dummy', 'dummy'])]]);

    assertSame(['a' => 3], Rules::peekPlan($towns, [], 1));
}

function test_a_troop_that_moved_does_not_peek(): void
{
    // Two troops arrived, one was already standing here: only that one looks.
    $towns = rulesBoard(['a' => ['troops' => 3, 'pile' => rulesPile(['influence', 'dummy'])]]);

    assertSame(['a' => 1], Rules::peekPlan($towns, ['a' => 2], 1));
}

function test_a_town_with_no_pile_is_not_peeked(): void
{
    $towns = rulesBoard(['a' => ['troops' => 2]]);

    assertSame([], Rules::peekPlan($towns, [], 1));
}

function test_the_empire_reads_the_newest_card_first_and_cycles_the_pile(): void
{
    // Decision 8: new cards go on top, a look takes from the top and returns to
    // the bottom, so a garrison eventually reads the whole pile.
    $pile = rulesPile(['influence', 'dummy', 'dummy']);

    $first = Rules::rotatePile($pile, 1);
    assertSame([1], $first['seen'], 'the top card is the newest one placed');
    assertSame([2, 3, 1], array_column($first['pile'], 'id'), 'and goes to the bottom');

    $second = Rules::rotatePile($first['pile'], 1);
    assertSame([2], $second['seen']);
}

function test_looking_at_more_cards_than_the_pile_holds_is_capped(): void
{
    $pile = rulesPile(['influence', 'dummy']);
    $rotated = Rules::rotatePile($pile, 5);

    assertSame([1, 2], $rotated['seen'], 'no re-reading cards you just saw');
    assertSame([1, 2], array_column($rotated['pile'], 'id'), 'a full cycle returns the pile as it was');
}
