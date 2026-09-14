<?php
/**
 * MistBot, the Insurgency that plays the map.
 *
 * The rules it follows are in the doc comment on Bots::mistInsurgencyTurn and
 * in CLAUDE.md under "The rebel bot that works". Each test below names one.
 *
 * The bot uses no randomness at all — every tie breaks on the board — so each
 * case here is exact. The last test replays 200 random boards recorded from
 * sim/bots.py and demands the same resolution and the same placements, card
 * for card and in order, on every one.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Bots;
use Bga\Games\IronAndWhisper\Scenario;

/**
 * A board on the scenario's real map, with every town empty and unresolved.
 *
 * @return array<string, array>
 */
function mistBoard(Scenario $scenario): array
{
    $towns = [];
    foreach ($scenario->towns as $townId => $definition) {
        $towns[$townId] = [
            'id' => $townId,
            'neighbors' => $definition['neighbors'],
            'supply' => $definition['supply'],
            'production' => $definition['production'],
            'troops' => 0,
            'resolved' => false,
            'winner' => null,
            'pile' => [],
            'revealed' => [],
        ];
    }
    return $towns;
}

/** Put `$count` face-down cards of the given value into a town's pile. */
function mistSeed(array $town, int $count, int $value = 1): array
{
    for ($i = 0; $i < $count; $i++) {
        $town['pile'][] = [
            'id' => 100 + $i, 'type' => "presence{$value}", 'presence' => $value, 'seen' => false,
        ];
    }
    return $town;
}

/**
 * A hand of the given presence values. Card `500 + i` is hand position i,
 * which is also how the parity fixture is read.
 *
 * @param int[] $values
 * @return array<int, array{id: int, type: string, presence: int}>
 */
function mistHand(array $values): array
{
    $hand = [];
    foreach ($values as $i => $value) {
        $hand[] = ['id' => 500 + $i, 'type' => "presence{$value}", 'presence' => $value];
    }
    return $hand;
}

/** @return int[] the hand positions placed in a town, in order */
function mistPlacedIn(array $plan, string $townId): array
{
    return array_map(
        static fn(int $cardId): int => $cardId - 500,
        $plan['placements'][$townId] ?? [],
    );
}

// -- resolution: everything already won -------------------------------------

function test_mist_cashes_a_town_it_has_already_beaten(): void
{
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['ashford']['troops'] = 1;
    $towns['ashford'] = mistSeed($towns['ashford'], 2);

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1, 1, 1]));
    assertSame('ashford', $plan['resolve']);
}

function test_mist_leaves_a_tie_alone_because_the_empire_wins_ties(): void
{
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['ashford']['troops'] = 2;
    $towns['ashford'] = mistSeed($towns['ashford'], 2);

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1, 1, 1]));
    assertSame(null, $plan['resolve']);
}

function test_mist_takes_the_richest_win_first(): void
{
    // Only one resolution a turn, and the score is the garrison overcome.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    foreach (['joss' => 1, 'coldwater' => 3, 'larrow' => 2] as $townId => $troops) {
        $towns[$townId]['troops'] = $troops;
        $towns[$townId] = mistSeed($towns[$townId], $troops + 1);
    }

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1, 1, 1]));
    assertSame('coldwater', $plan['resolve']);
}

function test_mist_breaks_a_score_tie_toward_the_busiest_neighbourhood(): void
{
    // Equal prizes, so take the one the Empire is likeliest to reinforce:
    // Joss touches an occupied Gallow, Larrow touches nobody.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['gallow']['troops'] = 1;
    foreach (['joss', 'larrow'] as $townId) {
        $towns[$townId]['troops'] = 1;
        $towns[$townId] = mistSeed($towns[$townId], 2);
    }

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1, 1, 1]));
    assertSame('joss', $plan['resolve']);
}

function test_mist_cashes_an_empty_town_for_nothing(): void
{
    // Worth no points and worth taking: the Empire can never supply it again.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['ashford'] = mistSeed($towns['ashford'], 1);

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1, 1, 1]));
    assertSame('ashford', $plan['resolve']);
}

function test_mist_does_not_place_into_the_town_it_just_resolved(): void
{
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['ashford']['troops'] = 1;
    $towns['ashford'] = mistSeed($towns['ashford'], 2);

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1, 1, 1]));
    assertSame('ashford', $plan['resolve']);
    assertSame([], mistPlacedIn($plan, 'ashford'));
}

// -- taking the lead --------------------------------------------------------

function test_mist_clears_the_garrison_by_exactly_one_with_the_fewest_cards(): void
{
    // Two troops need three presence; a single 3 does it and two 1s are saved.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['ashford']['troops'] = 2;

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([3, 1, 1]));
    assertSame([0], mistPlacedIn($plan, 'ashford'));
}

function test_mist_prefers_the_garrison_with_the_fewest_troops_in_reach(): void
{
    // Equal garrisons; Joss has three troops next door and Larrow has none.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['gallow']['troops'] = 3;
    $towns['joss']['troops'] = 1;
    $towns['larrow']['troops'] = 1;

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1, 1]));
    assertSame([0, 1], mistPlacedIn($plan, 'larrow'));
    assertSame([], mistPlacedIn($plan, 'joss'));
}

function test_mist_leads_in_a_second_town_when_the_hand_stretches(): void
{
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['joss']['troops'] = 1;
    $towns['larrow']['troops'] = 1;

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1, 1, 1, 1]));
    assertSame(2, count(mistPlacedIn($plan, 'joss')));
    assertSame(2, count(mistPlacedIn($plan, 'larrow')));
}

function test_mist_does_not_half_commit_to_a_lead_it_cannot_afford(): void
{
    // Half a lead is a donation: the Empire scores every card in a town it wins.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['ashford']['troops'] = 5;

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1, 1]));
    assertSame([], mistPlacedIn($plan, 'ashford'));
    assertSame(2, array_sum(array_map('count', $plan['placements'])));
}

// -- real cards with nothing to flip ----------------------------------------

function test_mist_seeds_an_empty_town_beside_a_garrison_that_can_spare_a_troop(): void
{
    // A lone troop marching out abandons its town, so Kirn — beside Joss and
    // its single troop — is not a place the Empire can come from. Draymoor,
    // beside three troops in Gallow, is.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['gallow']['troops'] = 3;
    $towns['joss']['troops'] = 1;

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([1]));
    assertSame([0], mistPlacedIn($plan, 'draymoor'));
    assertSame([], mistPlacedIn($plan, 'kirn'));
}

// -- bluffs -----------------------------------------------------------------

function test_mist_spreads_bluffs_one_empty_town_at_a_time(): void
{
    // A second bluff on the same town says nothing the first did not, so the
    // first two cards take the two empty towns beside Ashford's garrison.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['ashford']['troops'] = 2;

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([0, 0, 0]));
    assertSame(0, mistPlacedIn($plan, 'belmar')[0]);
    assertSame([1], mistPlacedIn($plan, 'draymoor'));
}

function test_mist_never_bluffs_a_town_no_troops_can_reach(): void
{
    // Nobody will ever walk into it, so the bluff has no audience.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['ashford']['troops'] = 2;

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([0, 0]));
    foreach (['joss', 'kirn', 'larrow', 'harrow', 'ilmen'] as $townId) {
        assertSame([], mistPlacedIn($plan, $townId));
    }
}

function test_mist_bluffs_the_closest_thing_to_a_tie_once_the_empty_towns_are_gone(): void
{
    // Every town is garrisoned, so there is no empty ground to seed. Belmar is
    // level with its garrison and every other town is a troop clear, so Belmar
    // is the only pile a card can change the Empire's reading of.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    foreach ($towns as $townId => $town) {
        $towns[$townId]['troops'] = 1;
    }
    $towns['belmar'] = mistSeed($towns['belmar'], 1);

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([0]));
    assertSame([0], mistPlacedIn($plan, 'belmar'));
}

function test_mist_falls_back_to_the_nearest_town_when_nothing_is_in_reach(): void
{
    // Forced: the whole hand must go out (Decision 6) even with no audience.
    // The only troops are in a resolved Ashford, two hops from Coldwater.
    $scenario = Scenario::load('baseline');
    $towns = mistBoard($scenario);
    $towns['ashford']['troops'] = 2;
    foreach (['ashford', 'belmar', 'draymoor'] as $townId) {
        $towns[$townId]['resolved'] = true;
    }

    $plan = Bots::mistInsurgencyTurn($scenario, $towns, mistHand([0]));
    assertSame([0], mistPlacedIn($plan, 'coldwater'));
}

// -- the whole hand, every turn ---------------------------------------------

function test_mist_places_its_entire_hand_every_turn(): void
{
    $scenario = Scenario::load('baseline');
    mt_srand(4);

    for ($trial = 0; $trial < 40; $trial++) {
        $towns = mistBoard($scenario);
        foreach ($towns as $townId => $town) {
            $towns[$townId]['troops'] = mt_rand(0, 3);
            $towns[$townId]['resolved'] = mt_rand(0, 3) === 0;
            if (!$towns[$townId]['resolved']) {
                $towns[$townId] = mistSeed($towns[$townId], mt_rand(0, 3));
            }
        }
        $hand = mistHand([mt_rand(0, 1), mt_rand(0, 1), mt_rand(0, 1)]);

        $plan = Bots::mistInsurgencyTurn($scenario, $towns, $hand);
        $placed = array_merge(...array_values($plan['placements'] ?: [[]]));
        sort($placed);

        $open = false;
        foreach ($towns as $townId => $town) {
            if (!$town['resolved'] && $townId !== $plan['resolve']) {
                $open = true;
            }
        }
        assertSame($open ? [500, 501, 502] : [], $placed, "trial {$trial}");

        foreach (array_keys($plan['placements']) as $townId) {
            assertTrue(!$towns[$townId]['resolved'], "placed into resolved {$townId}");
            assertTrue($townId !== $plan['resolve'], "placed into the town it resolved");
        }
    }
}

// -- against the simulator, position by position ----------------------------

function test_mist_matches_the_simulator_on_recorded_positions(): void
{
    $path = __DIR__ . '/fixtures/mist_parity.jsonl';
    $lines = array_filter(explode("\n", (string) file_get_contents($path)));
    assertTrue(count($lines) > 100, 'fixture looks truncated');

    $scenarios = [];
    $mismatches = [];

    foreach ($lines as $index => $line) {
        $fixture = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $scenarioId = $fixture['scenario'];
        $scenario = $scenarios[$scenarioId] ??= Scenario::load($scenarioId);

        $towns = mistBoard($scenario);
        foreach ($fixture['position'] as $townId => $recorded) {
            $towns[$townId]['troops'] = (int) $recorded['troops'];
            $towns[$townId]['resolved'] = (bool) $recorded['resolved'];
            $towns[$townId]['winner'] = $recorded['winner'];
            foreach (['pile', 'revealed'] as $where) {
                foreach ($recorded[$where] as $i => $value) {
                    $towns[$townId][$where][] = [
                        'id' => ($where === 'pile' ? 1000 : 2000) + $i,
                        'type' => "presence{$value}",
                        'presence' => (int) $value,
                        'seen' => $where === 'revealed',
                    ];
                }
            }
        }

        $turn = Bots::mistInsurgencyTurn($scenario, $towns, mistHand($fixture['hand']));
        $placements = [];
        foreach ($turn['placements'] as $townId => $cardIds) {
            $placements[$townId] = array_map(
                static fn(int $cardId): int => $cardId - 500,
                $cardIds,
            );
        }
        ksort($placements);

        $got = ['resolve' => $turn['resolve'], 'placements' => $placements];
        foreach (['resolve', 'placements'] as $field) {
            $want = json_encode($fixture['decision'][$field]);
            $mine = json_encode($got[$field]);
            if ($mine !== $want) {
                $mismatches[] = "#{$index} {$field}: php {$mine} vs sim {$want}";
                break;
            }
        }
    }

    assertSame(
        [],
        array_slice($mismatches, 0, 5),
        count($mismatches) . ' of ' . count($lines) . ' positions disagree with sim/bots.py',
    );
}
