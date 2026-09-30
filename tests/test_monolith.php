<?php
/**
 * ImperialMonolithBot, ported: Bots::monolithEmpireTurn against sim/bots.py.
 *
 * The simulator is the specification, and its own tests (sim/test_bots.py,
 * sim/test_replay.py) pin each rule. This file pins the port: 300 random
 * boards from `sim.parity --bot monolith`, on which both engines must produce
 * the same resolve, produce, moves and disband exactly — moves in order.
 * Regenerate the fixture after any deliberate change to the Python bot; it is
 * the PHP that moves.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Bots;
use Bga\Games\IronAndWhisper\Scenario;

/** An empty board from the scenario's map, as the bots see one. */
function monolithBoard(Scenario $scenario): array
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

function test_monolith_decides_exactly_what_the_simulator_decides(): void
{
    $path = __DIR__ . '/fixtures/monolith_parity.jsonl';
    $lines = array_filter(explode("\n", (string) file_get_contents($path)));
    assertTrue(count($lines) > 100, 'fixture looks truncated');

    $scenarios = [];
    $mismatches = [];

    foreach ($lines as $index => $line) {
        $fixture = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $scenarioId = $fixture['scenario'];
        $scenario = $scenarios[$scenarioId] ??= Scenario::load($scenarioId);

        $towns = monolithBoard($scenario);
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

        $turn = Bots::monolithEmpireTurn($scenario, $towns);
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
