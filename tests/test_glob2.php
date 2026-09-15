<?php
/**
 * Glob2Empire, GlobEmpire with a tighter leash on isolated garrisons.
 *
 * The rules are in the doc comment on Bots::glob2EmpireTurn, on
 * Glob2Empire in sim/bots.py, and in issue #18. Each test contrasts the two
 * bots on the same board: GlobEmpire's GLOB_RETREAT_MARGIN (5) does not
 * react to a deficit this small, and Glob2Empire's GLOB2_ISOLATED_MARGIN (1)
 * does, but only for a garrison outside the Empire's main component.
 *
 * The real map (see CLAUDE.md's "How the port is put together") gives real
 * distances: Ashford to Larrow is four hops, and Ashford to Joss/Kirn is
 * three and four — none of them adjacent to Ashford, so a garrison there is
 * genuinely a separate component from one at Ashford as long as nothing
 * stands on the towns between them.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Bots;
use Bga\Games\IronAndWhisper\Scenario;

/** @return string[] the towns this plan marches into, sorted */
function glob2Destinations(array $plan): array
{
    $to = array_map(static fn(array $move) => $move['to'], $plan['moves']);
    sort($to);
    return $to;
}

/** @return array{from: string, to: string, count: int}[] moves leaving a town */
function glob2MovesFrom(array $plan, string $townId): array
{
    return array_values(array_filter(
        $plan['moves'],
        static fn(array $m) => $m['from'] === $townId,
    ));
}

function test_glob2_withdraws_an_isolated_garrison_the_base_bot_leaves_standing(): void
{
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['ashford']['troops'] = 6;              // the main army
    $towns['larrow']['troops'] = 1;                // a lone outpost, 4 hops away
    $towns['larrow'] = globSeed($towns['larrow'], 2); // worstCase 2, deficit 1

    $basePlan = Bots::globEmpireTurn($scenario, $towns);
    assertSame([], glob2MovesFrom($basePlan, 'larrow'), 'GLOB_RETREAT_MARGIN should ignore a deficit of 1');

    $plan = Bots::glob2EmpireTurn($scenario, $towns);
    $out = glob2MovesFrom($plan, 'larrow');
    assertTrue($out !== [], 'Glob2 should react to any real threat against an isolated garrison');
}

function test_glob2_reinforces_an_isolated_garrison_the_base_bot_ignores(): void
{
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['ashford']['troops'] = 6;               // the main army
    $towns['joss']['troops'] = 3;                  // a small, separate garrison
    $towns['kirn']['troops'] = 1;                  // next door to Joss, threatened
    $towns['kirn'] = globSeed($towns['kirn'], 2);   // worstCase 2, deficit 1

    $basePlan = Bots::globEmpireTurn($scenario, $towns);
    $baseRelief = array_filter(glob2MovesFrom($basePlan, 'joss'), static fn(array $m) => $m['to'] === 'kirn');
    assertSame([], array_values($baseRelief), 'GLOB_RETREAT_MARGIN should ignore a deficit of 1');

    $plan = Bots::glob2EmpireTurn($scenario, $towns);
    $relief = array_filter(glob2MovesFrom($plan, 'joss'), static fn(array $m) => $m['to'] === 'kirn');
    assertTrue(array_sum(array_map(static fn(array $m) => $m['count'], $relief)) > 0,
        'Glob2 should send Joss to relieve Kirn at a deficit of 1');
}

function test_glob2_still_tolerates_a_bluff_sized_threat_inside_the_main_army(): void
{
    // One component, one army: the isolated margin never applies, and Glob2
    // matches GlobEmpire exactly. Same board as
    // test_glob_holds_when_it_is_behind_by_less_than_the_margin.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['belmar']['troops'] = 3;
    $towns['belmar'] = globSeed($towns['belmar'], 5);

    $out = glob2MovesFrom(Bots::glob2EmpireTurn($scenario, $towns), 'belmar');
    assertSame([], $out);
}

function test_glob2_still_expands_into_several_towns_at_once(): void
{
    // The wave-expansion behaviour is unchanged from GlobEmpire.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 4;

    $destinations = glob2Destinations(Bots::glob2EmpireTurn($scenario, $towns));
    assertTrue(count($destinations) >= 3, 'expected a wave, got ' . implode(',', $destinations));
}

function test_glob2_never_leaves_a_network_it_cannot_feed(): void
{
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 4;

    $plan = Bots::glob2EmpireTurn($scenario, $towns);
    foreach ($plan['moves'] as $move) {
        $towns[$move['from']]['troops'] -= $move['count'];
        $towns[$move['to']]['troops'] += $move['count'];
    }
    foreach ($plan['produce'] as $townId => $count) {
        $towns[$townId]['troops'] += $count;
    }
    foreach (\Bga\Games\IronAndWhisper\Rules::components($towns) as $component) {
        assertTrue(
            \Bga\Games\IronAndWhisper\Rules::troopsIn($towns, $component)
                <= \Bga\Games\IronAndWhisper\Rules::ceiling($towns, $component, $scenario->supplyPerTroop),
            'left ' . implode(',', $component) . ' over its ceiling',
        );
    }
}
