<?php
/**
 * GlobEmpire, the bot that plays for its supply network.
 *
 * The rules it follows are in the doc comment on Bots::globEmpireTurn and in
 * CLAUDE.md under "The Empire bot that works". Each test below names one.
 *
 * The last test is the important one: it replays 150 random boards recorded
 * from sim/bots.py and demands the same decision on every field of every one.
 * tests/selfplay.php compares the engines statistically, which is too blunt
 * here — scores are small integers and a fifth of games are draws, so win rate
 * hides a real difference for a thousand games at a time.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Bots;
use Bga\Games\IronAndWhisper\Rules;
use Bga\Games\IronAndWhisper\Scenario;

/**
 * A board on the scenario's real map, with every town empty and unresolved.
 *
 * @return array<string, array>
 */
function globBoard(Scenario $scenario): array
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
function globSeed(array $town, int $count, int $value = 1): array
{
    for ($i = 0; $i < $count; $i++) {
        $town['pile'][] = [
            'id' => 100 + $i, 'type' => "influence{$value}", 'influence' => $value, 'seen' => false,
        ];
    }
    return $town;
}

/** @return string[] the towns this plan marches into */
function globDestinations(array $plan): array
{
    $to = array_map(static fn(array $move) => $move['to'], $plan['moves']);
    sort($to);
    return $to;
}

// -- resolution: certain wins only ------------------------------------------

function test_glob_leaves_alone_a_pile_it_could_lose_to(): void
{
    // Three face-down cards could be worth three, so two troops are not enough,
    // even though the deck is 40% bluffs and two is the likelier value.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 2;
    $towns['everlan'] = globSeed($towns['everlan'], 3);

    assertSame(null, Bots::globEmpireTurn($scenario, $towns)['resolve']);
}

function test_glob_takes_a_pile_it_cannot_lose_to(): void
{
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 3;
    $towns['everlan'] = globSeed($towns['everlan'], 3);

    assertSame('everlan', Bots::globEmpireTurn($scenario, $towns)['resolve']);
}

function test_glob_counts_a_tie_as_certain_because_the_empire_wins_ties(): void
{
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 2;
    $towns['everlan'] = globSeed($towns['everlan'], 2);

    assertSame('everlan', Bots::globEmpireTurn($scenario, $towns)['resolve']);
}

function test_glob_counts_a_card_it_has_seen_at_its_real_value(): void
{
    // A look moves a card face up, so a peek sharpens the bound for free: two
    // face-down cards would need two troops, but one of them is a known bluff.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 1;
    $towns['everlan'] = globSeed($towns['everlan'], 1);
    $towns['everlan']['revealed'][] = [
        'id' => 9, 'type' => 'influence0', 'influence' => 0, 'seen' => true,
    ];

    assertSame('everlan', Bots::globEmpireTurn($scenario, $towns)['resolve']);
}

function test_glob_takes_the_richest_certain_win(): void
{
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    foreach (['ashford' => 1, 'belmar' => 3, 'coldwater' => 2] as $townId => $cards) {
        $towns[$townId]['troops'] = 3;
        $towns[$townId] = globSeed($towns[$townId], $cards);
    }

    assertSame('belmar', Bots::globEmpireTurn($scenario, $towns)['resolve']);
}

function test_glob_marches_out_of_the_town_it_just_resolved(): void
{
    // A certain win keeps its garrison (Decision 3), so the troops still exist.
    // The heuristic bot holds still where it is fighting because it cannot know
    // how the fight went; this one has already checked.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 3;
    $towns['everlan'] = globSeed($towns['everlan'], 1);

    $plan = Bots::globEmpireTurn($scenario, $towns);
    assertSame('everlan', $plan['resolve']);
    $out = array_filter($plan['moves'], static fn(array $m) => $m['from'] === 'everlan');
    assertTrue($out !== [], 'the garrison of a town it is certain of may march on');
}

// -- expansion: the wave ----------------------------------------------------

function test_glob_expands_into_several_towns_at_once(): void
{
    // A three-card hand cannot contest three new towns in one reply.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 4;

    $destinations = globDestinations(Bots::globEmpireTurn($scenario, $towns));
    assertTrue(count($destinations) >= 3, 'expected a wave, got ' . implode(',', $destinations));
}

function test_glob_sends_exactly_enough_to_be_certain_of_a_seeded_town(): void
{
    // A seeded town it can beat is preferred to an empty one — the supply is
    // identical and the influence is points it will collect next turn — but it
    // pays no more for it than certainty costs.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 3;
    $neighbor = $scenario->towns['everlan']['neighbors'][0];
    $towns[$neighbor] = globSeed($towns[$neighbor], 2);

    $plan = Bots::globEmpireTurn($scenario, $towns);
    $sent = 0;
    foreach ($plan['moves'] as $move) {
        if ($move['to'] === $neighbor) {
            $sent += $move['count'];
        }
    }
    assertSame(2, $sent);
}

function test_glob_does_not_walk_into_a_town_it_cannot_be_certain_of(): void
{
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 2;
    $neighbor = $scenario->towns['everlan']['neighbors'][0];
    $towns[$neighbor] = globSeed($towns[$neighbor], 5);

    $destinations = globDestinations(Bots::globEmpireTurn($scenario, $towns));
    assertFalse(in_array($neighbor, $destinations, true));
}

function test_glob_never_leaves_a_network_it_cannot_feed(): void
{
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 4;

    $plan = Bots::globEmpireTurn($scenario, $towns);
    foreach ($plan['moves'] as $move) {
        $towns[$move['from']]['troops'] -= $move['count'];
        $towns[$move['to']]['troops'] += $move['count'];
    }
    foreach ($plan['produce'] as $townId => $count) {
        $towns[$townId]['troops'] += $count;
    }
    foreach (Rules::components($towns) as $component) {
        assertTrue(
            Rules::troopsIn($towns, $component)
                <= Rules::ceiling($towns, $component, $scenario->supplyPerTroop),
            'left ' . implode(',', $component) . ' over its ceiling',
        );
    }
}

// -- retreat and relief -----------------------------------------------------

function test_glob_reinforces_a_garrison_that_can_be_saved(): void
{
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['belmar']['troops'] = 1;
    $towns['belmar'] = globSeed($towns['belmar'], 6);
    $towns['everlan']['troops'] = 8;   // Everlan and Belmar are adjacent

    $relief = 0;
    foreach (Bots::globEmpireTurn($scenario, $towns)['moves'] as $move) {
        if ($move['to'] === 'belmar') {
            $relief += $move['count'];
        }
    }
    assertSame(5, $relief, 'relief should take the garrison to exactly six');
}

function test_glob_withdraws_a_garrison_that_cannot_be_saved(): void
{
    // Half a relief column loses the column as well as the town.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['belmar']['troops'] = 1;
    $towns['belmar'] = globSeed($towns['belmar'], 6);

    $out = array_filter(
        Bots::globEmpireTurn($scenario, $towns)['moves'],
        static fn(array $m) => $m['from'] === 'belmar',
    );
    assertSame(1, array_sum(array_map(static fn(array $m) => $m['count'], $out)));
}

function test_glob_holds_when_it_is_behind_by_less_than_the_margin(): void
{
    // worstCase assumes every hidden card is the best in the deck, and most are
    // not. Withdrawing from every pile that could beat you hands towns to bluffs.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['belmar']['troops'] = 3;
    $towns['belmar'] = globSeed($towns['belmar'], 5);

    $out = array_filter(
        Bots::globEmpireTurn($scenario, $towns)['moves'],
        static fn(array $m) => $m['from'] === 'belmar',
    );
    assertSame([], array_values($out));
}

// -- production -------------------------------------------------------------

function test_glob_builds_past_the_ceiling_the_march_is_about_to_raise(): void
{
    // Everlan supplies 2 and holds 2, so there is no headroom until the troops
    // move — and they move.
    $scenario = Scenario::load('baseline');
    $towns = globBoard($scenario);
    $towns['everlan']['troops'] = 2;

    $plan = Bots::globEmpireTurn($scenario, $towns);
    assertTrue(
        ($plan['produce']['everlan'] ?? 0) >= 1,
        'a build the march will feed is a build that fits',
    );
}

// -- the port ---------------------------------------------------------------

function test_glob_decides_exactly_what_the_simulator_decides(): void
{
    $path = __DIR__ . '/fixtures/glob_parity.jsonl';
    $lines = array_filter(explode("\n", (string) file_get_contents($path)));
    assertTrue(count($lines) > 100, 'fixture looks truncated');

    $scenarios = [];
    $mismatches = [];

    foreach ($lines as $index => $line) {
        $fixture = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $scenarioId = $fixture['scenario'];
        $scenario = $scenarios[$scenarioId] ??= Scenario::load($scenarioId);

        $towns = globBoard($scenario);
        foreach ($fixture['position'] as $townId => $recorded) {
            $towns[$townId]['troops'] = (int) $recorded['troops'];
            $towns[$townId]['resolved'] = (bool) $recorded['resolved'];
            $towns[$townId]['winner'] = $recorded['winner'];
            foreach (['pile', 'revealed'] as $where) {
                foreach ($recorded[$where] as $i => $value) {
                    $towns[$townId][$where][] = [
                        'id' => ($where === 'pile' ? 1000 : 2000) + $i,
                        'type' => "influence{$value}",
                        'influence' => (int) $value,
                        'seen' => $where === 'revealed',
                    ];
                }
            }
        }

        $turn = Bots::globEmpireTurn($scenario, $towns);
        ksort($turn['produce']);
        ksort($turn['disband']);
        $got = [
            'resolve' => $turn['resolve'],
            'produce' => $turn['produce'],
            'moves' => array_map(
                static fn(array $m) => [$m['from'], $m['to'], $m['count']],
                $turn['moves'],
            ),
            'disband' => $turn['disband'],
        ];

        foreach (['resolve', 'produce', 'moves', 'disband'] as $field) {
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
