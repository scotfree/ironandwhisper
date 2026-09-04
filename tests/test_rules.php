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
    // Card type ids carry their value: influence0 through influence3.
    return ['id' => $id, 'type' => $type, 'influence' => (int) substr($type, -1)];
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
        [
            'neighbors' => [], 'troops' => 0, 'resolved' => false,
            'pile' => [], 'revealed' => [],
            'supply' => 1, 'production' => 0,
        ],
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
    $towns = rulesBoard(['a' => ['troops' => 2, 'pile' => rulesPile(['influence1', 'influence1'])]]);
    $outcome = Rules::resolveTown($towns, 'a', STRENGTH, true);

    assertSame(Rules::EMPIRE, $outcome['winner'], '6 strength beats 2 influence');
    assertSame(2, $outcome['points'], 'the Empire banks the influence it suppressed');
}

function test_insurgency_wins_and_scores_the_strength_it_overcame(): void
{
    $towns = rulesBoard(['a' => ['troops' => 1, 'pile' => rulesPile(array_fill(0, 5, 'influence1'))]]);
    $outcome = Rules::resolveTown($towns, 'a', STRENGTH, true);

    assertSame(Rules::INSURGENCY, $outcome['winner'], '5 influence beats 3 strength');
    assertSame(3, $outcome['points'], 'the Insurgency banks the strength it absorbed');
}

function test_dummies_are_worth_nothing_so_a_bluff_pays_the_empire_nothing(): void
{
    $towns = rulesBoard(['a' => ['troops' => 2, 'pile' => rulesPile(['influence0', 'influence0', 'influence0'])]]);
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
    $towns = rulesBoard(['a' => ['troops' => 1, 'pile' => rulesPile(['influence1', 'influence1', 'influence1'])]]);
    $outcome = Rules::resolveTown($towns, 'a', STRENGTH, true);

    assertSame(Rules::EMPIRE, $outcome['winner'], 'Decision 7: 3 against 3 goes to the Empire');
    assertSame(3, $outcome['points']);
}

function test_tiebreaker_is_configurable(): void
{
    $towns = rulesBoard(['a' => ['troops' => 1, 'pile' => rulesPile(['influence1', 'influence1', 'influence1'])]]);
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
    $towns = rulesBoard(['a' => ['troops' => 0, 'pile' => rulesPile(['influence1'])]]);

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
    $towns = rulesBoard(['a' => ['troops' => 2, 'pile' => rulesPile(['influence1']), 'resolved' => true]]);

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

// -- supply and production ---------------------------------------------------

function test_a_network_is_the_towns_the_empire_stands_in(): void
{
    $towns = rulesBoard(['a' => ['troops' => 1], 'b' => ['troops' => 1]]);
    assertSame([['a', 'b']], Rules::components($towns));

    // With b empty there is no road between a and c, so they are two networks.
    $split = rulesBoard(['a' => ['troops' => 1], 'c' => ['troops' => 1]]);
    assertSame([['a'], ['c']], Rules::components($split));
}

function test_the_ceiling_is_the_networks_summed_supply(): void
{
    $towns = rulesBoard([
        'a' => ['troops' => 1, 'supply' => 2],
        'b' => ['troops' => 1, 'supply' => 3],
    ]);

    assertSame(5, Rules::ceiling($towns, ['a', 'b'], 1));
    assertSame(2, Rules::troopsIn($towns, ['a', 'b']));
    assertSame(3, Rules::headroom($towns, 'a', 1), 'five supply, two troops standing');
}

function test_a_resolved_town_still_carries_supply(): void
{
    // The Empire won here and kept its garrison, so the town is still network.
    $towns = rulesBoard([
        'a' => ['troops' => 2, 'supply' => 2, 'resolved' => true],
        'b' => ['troops' => 1, 'supply' => 2],
    ]);

    assertSame([['a', 'b']], Rules::components($towns));
    assertSame(4, Rules::ceiling($towns, ['a', 'b'], 1));
}

function test_building_needs_presence_production_and_supply(): void
{
    $towns = rulesBoard([
        'a' => ['troops' => 1, 'supply' => 3, 'production' => 1],
        'b' => ['troops' => 0, 'supply' => 3, 'production' => 1],
    ]);

    assertSame(['a'], Rules::productionSites($towns, 1), 'b is nobody\'s until someone stands in it');
    Rules::validateProduction($towns, ['a' => 1], 1, 1);

    assertThrows(
        IllegalMove::class,
        fn() => Rules::validateProduction($towns, ['b' => 1], 1, 1),
        'no presence, no building',
    );
    assertThrows(
        IllegalMove::class,
        fn() => Rules::validateProduction($towns, ['a' => 2], 1, 1),
        'the town can only build one a turn',
    );
}

function test_building_stops_at_the_ceiling(): void
{
    $towns = rulesBoard(['a' => ['troops' => 1, 'supply' => 1, 'production' => 5]]);

    assertSame(0, Rules::headroom($towns, 'a', 1), 'one supply, already spent');
    assertThrows(
        IllegalMove::class,
        fn() => Rules::validateProduction($towns, ['a' => 1], 1, 1),
        'production is not the constraint here, supply is',
    );
}

function test_two_sites_in_one_network_share_one_ceiling(): void
{
    $towns = rulesBoard([
        'a' => ['troops' => 1, 'supply' => 2, 'production' => 5],
        'b' => ['troops' => 1, 'supply' => 2, 'production' => 5],
    ]);

    // Four supply, two troops standing, so two more between them — not two each.
    Rules::validateProduction($towns, ['a' => 1, 'b' => 1], 1, 1);
    assertThrows(
        IllegalMove::class,
        fn() => Rules::validateProduction($towns, ['a' => 2, 'b' => 1], 1, 1),
    );
}

// -- attrition ---------------------------------------------------------------

function test_troops_a_network_cannot_supply_starve(): void
{
    $towns = rulesBoard(['a' => ['troops' => 3, 'supply' => 1]]);

    assertSame(['a' => 2], Rules::attritionPlan($towns, 1));
}

function test_a_supplied_network_starves_nobody(): void
{
    $towns = rulesBoard([
        'a' => ['troops' => 1, 'supply' => 2],
        'b' => ['troops' => 1, 'supply' => 2],
    ]);

    assertSame([], Rules::attritionPlan($towns, 1));
}

function test_the_empire_chooses_where_attrition_falls(): void
{
    $towns = rulesBoard([
        'a' => ['troops' => 2, 'supply' => 1],
        'b' => ['troops' => 2, 'supply' => 1],
    ]);

    // Four troops, two supply: two starve, and the Empire says which.
    assertSame(['b' => 2], Rules::attritionPlan($towns, 1, ['b' => 2]));
}

function test_severing_a_line_leaves_two_smaller_ceilings(): void
{
    $held = ['troops' => 1, 'supply' => 1];
    $towns = rulesBoard(['a' => $held, 'b' => $held, 'c' => $held]);
    assertSame(3, Rules::ceiling($towns, Rules::componentOf($towns, 'a'), 1));

    // The Insurgency takes the middle town: one network becomes two.
    $cut = rulesBoard(['a' => $held, 'c' => $held]);
    assertSame(1, Rules::ceiling($cut, Rules::componentOf($cut, 'a'), 1));
    assertSame(1, Rules::ceiling($cut, Rules::componentOf($cut, 'c'), 1));
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
    $towns = rulesBoard(['a' => ['troops' => 1, 'pile' => rulesPile(['influence1', 'influence0', 'influence0'])]]);

    assertSame(['a' => 1], Rules::peekPlan($towns, [], 1));
}

function test_peeks_stack_across_stationary_troops(): void
{
    $towns = rulesBoard(['a' => ['troops' => 3, 'pile' => rulesPile(['influence1', 'influence0', 'influence0'])]]);

    assertSame(['a' => 3], Rules::peekPlan($towns, [], 1));
}

function test_a_troop_that_moved_does_not_peek(): void
{
    // Two troops arrived, one was already standing here: only that one looks.
    $towns = rulesBoard(['a' => ['troops' => 3, 'pile' => rulesPile(['influence1', 'influence0'])]]);

    assertSame(['a' => 1], Rules::peekPlan($towns, ['a' => 2], 1));
}

function test_a_town_with_no_pile_is_not_peeked(): void
{
    $towns = rulesBoard(['a' => ['troops' => 2]]);

    assertSame([], Rules::peekPlan($towns, [], 1));
}

function test_the_empire_reads_the_newest_card_first(): void
{
    // Decision 8: new cards go on top, and a look turns the top card face up.
    $pile = rulesPile(['influence1', 'influence0', 'influence0']);

    assertSame([1], Rules::revealFromPile($pile, 1), 'the top card is the newest one placed');
    assertSame([1, 2], Rules::revealFromPile($pile, 2), 'two looks take the top two');
}

function test_a_look_cannot_take_more_than_the_pile_holds(): void
{
    // The pile only ever holds cards nobody has seen, so there is nothing to
    // re-read and nothing to cap beyond running out.
    assertSame([1, 2], Rules::revealFromPile(rulesPile(['influence1', 'influence0']), 5));
    assertSame([], Rules::revealFromPile([], 3), 'a town read to the bottom yields nothing');
}

function test_face_up_cards_still_count_towards_the_town(): void
{
    $town = rulesTown([
        'troops' => 1,
        'pile' => rulesPile(['influence1', 'influence0']),
        'revealed' => rulesPile(['influence1', 'influence1'], 10),
    ]);

    assertSame(3, Rules::townInfluence($town), 'face up is not out of play');
    assertSame(4, Rules::townCardCount($town));
}

function test_the_insurgency_holds_a_town_it_has_only_face_up_cards_in(): void
{
    // Everything read does not mean everything gone: presence is presence.
    $towns = rulesBoard(['a' => ['revealed' => rulesPile(['influence1'])]]);

    assertTrue(Rules::canDeclare($towns, 'a', Rules::INSURGENCY));
}
