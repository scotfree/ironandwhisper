<?php
/**
 * Mist2Insurgency, MistBot aimed at the garrison rather than away from it.
 *
 * The rule is in the doc comment on Bots::mist2LeadTarget, on
 * Mist2Insurgency in sim/bots.py, and in issue #18: mistLeadTarget sorts by
 * fewest troops in reach first, which steers every lead away from a
 * concentrated Empire army — the only place with real points on it. Mist2
 * sorts by richness first and keeps the adjacency rule only as a tie-break.
 *
 * Reuses mistBoard/mistHand/mistPlacedIn from test_mist.php.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Bots;
use Bga\Games\IronAndWhisper\Scenario;

function test_mist2_leads_the_richer_garrison_even_though_it_is_better_defended(): void
{
    // Everlan is the bigger prize but sits next to Belmar's 5-troop garrison;
    // Larrow is poorer but has nothing next door. Neither hand affords
    // Belmar at all.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['belmar']['troops'] = 5;   // too expensive for either bot to consider
    $towns['everlan']['troops'] = 3;  // richer, and defended by Belmar next door
    $towns['larrow']['troops'] = 1;   // poorer, and undefended

    $hand = mistHand([2, 2]);

    $basePlan = Bots::mistInsurgencyTurn($scenario, $towns, $hand);
    assertSame([0], mistPlacedIn($basePlan, 'larrow'), 'MistBot should prefer the undefended town');
    assertSame([], mistPlacedIn($basePlan, 'everlan'));

    $plan = Bots::mist2InsurgencyTurn($scenario, $towns, $hand);
    assertSame([0, 1], mistPlacedIn($plan, 'everlan'), 'Mist2 should prefer the richer town instead');
    assertSame([], mistPlacedIn($plan, 'larrow'));
}

function test_mist2_still_breaks_a_tie_between_equally_rich_targets_by_safety(): void
{
    // Equal garrisons at Ashford and Joss; Draymoor's extra troops only touch
    // Ashford's adjacency count, so Joss remains the safer target and both
    // bots agree on it.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['draymoor']['troops'] = 4; // adjacent to Ashford only, not Joss
    $towns['ashford']['troops'] = 1;
    $towns['joss']['troops'] = 1;

    $hand = mistHand([1, 1]);
    $plan = Bots::mist2InsurgencyTurn($scenario, $towns, $hand);
    assertSame([0, 1], mistPlacedIn($plan, 'joss'));
    assertSame([], mistPlacedIn($plan, 'ashford'));
}

function test_mist2_plays_legal_turns_on_the_real_scenario(): void
{
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['everlan']['troops'] = 3;
    $towns['harrow']['troops'] = 2;

    $plan = Bots::mist2InsurgencyTurn($scenario, $towns, mistHand([1, 1, 1]));
    $placed = [];
    foreach ($plan['placements'] as $ids) {
        foreach ($ids as $id) {
            $placed[] = $id - 500;
        }
    }
    sort($placed);
    assertSame([0, 1, 2], $placed, 'the whole hand must be placed (Decision 6)');
}
