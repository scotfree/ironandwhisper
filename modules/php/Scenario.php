<?php
/**
 * Iron and Whisper — scenario loading.
 *
 * The PHP reads the same data/, maps/ and scenarios/ JSON as sim/config.py.
 * That sharing is deliberate: it is what makes the simulator's tuning work
 * transfer to the real game. Changing the shape of those files means changing
 * both sides.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper;

final class Scenario
{
    /** Directory holding data/, maps/ and scenarios/ — the project root. */
    private const ROOT = __DIR__ . '/../..';

    /**
     * @param array<string, array{id: string, label: string, x: float, y: float, neighbors: string[]}> $towns
     * @param array{id: string, label: string, strength: int, movement: int, peek: int} $unit
     * @param array<string, array{id: string, label: string, influence: int}> $cardTypes
     * @param array<string, int> $deck        card type id => quantity
     * @param array<string, int> $empireStart town id => starting troops
     * @param array<array{0: string, 1: string}> $edges
     */
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $mapId,
        public readonly array $towns,
        public readonly array $edges,
        public readonly array $unit,
        public readonly array $cardTypes,
        public readonly int $handSize,
        public readonly array $deck,
        public readonly int $supplyPerTroop,
        public readonly int $productionCost,
        public readonly array $empireStart,
        public readonly string $firstPlayer,
        public readonly bool $empireWinsTies,
    ) {
    }

    // Note: there is no `consumeTroops` knob here, unlike the simulator. Troops
    // are always spent at resolution (Decision 3). The simulator can turn that
    // off because measuring the alternative is how we learned it breaks the
    // game — the Empire wins 99.7%. Nothing in the shipped game should be able
    // to reach that configuration.

    public static function load(string $scenarioId): self
    {
        $scenario = self::readJson(self::ROOT . "/scenarios/{$scenarioId}.json");
        $map = self::readJson(self::ROOT . "/maps/{$scenario['map']}.json");
        $units = self::readJson(self::ROOT . '/data/units.json');
        $cards = self::readJson(self::ROOT . '/data/cards.json');

        $towns = [];
        foreach ($map['towns'] as $town) {
            $towns[$town['id']] = [
                'id' => $town['id'],
                'label' => $town['label'],
                'x' => (float) $town['x'],
                'y' => (float) $town['y'],
                // What the town adds to the ceiling of whatever network holds
                // it, and how much it can build each turn. Independent: a poor
                // town can be a depot, a rich one can build nothing.
                'supply' => (int) ($town['supply'] ?? 1),
                'production' => (int) ($town['production'] ?? 0),
                'neighbors' => [],
            ];
        }

        $edges = [];
        foreach ($map['edges'] as [$a, $b]) {
            if (!isset($towns[$a]) || !isset($towns[$b])) {
                throw new \RuntimeException("map {$map['id']}: edge references unknown town {$a}-{$b}");
            }
            $towns[$a]['neighbors'][] = $b;
            $towns[$b]['neighbors'][] = $a;
            $edges[] = [$a, $b];
        }
        foreach ($towns as $townId => $town) {
            sort($towns[$townId]['neighbors']);
        }

        $unitId = $scenario['unit'];
        if (!isset($units[$unitId])) {
            throw new \RuntimeException("scenario {$scenarioId}: unknown unit {$unitId}");
        }
        $unit = $units[$unitId] + ['id' => $unitId];

        $cardTypes = [];
        foreach ($cards as $typeId => $card) {
            $cardTypes[$typeId] = $card + ['id' => $typeId];
        }
        $unknown = array_diff(array_keys($scenario['deck']), array_keys($cardTypes));
        if ($unknown) {
            throw new \RuntimeException(
                "scenario {$scenarioId}: unknown card types " . implode(', ', $unknown)
            );
        }

        foreach (array_keys($scenario['empire_start']) as $townId) {
            if (!isset($towns[$townId])) {
                throw new \RuntimeException("scenario {$scenarioId}: empire_start names unknown town {$townId}");
            }
        }

        return new self(
            id: $scenario['id'],
            label: $scenario['label'],
            mapId: $map['id'],
            towns: $towns,
            edges: $edges,
            unit: $unit,
            cardTypes: $cardTypes,
            handSize: (int) $scenario['hand_size'],
            deck: array_map('intval', $scenario['deck']),
            supplyPerTroop: (int) ($scenario['supply_per_troop'] ?? 1),
            productionCost: (int) ($scenario['production_cost'] ?? 1),
            empireStart: array_map('intval', $scenario['empire_start']),
            firstPlayer: $scenario['first_player'],
            empireWinsTies: (bool) $scenario['empire_wins_ties'],
        );
    }

    public function unitStrength(): int
    {
        return (int) $this->unit['strength'];
    }

    public function unitPeek(): int
    {
        return (int) $this->unit['peek'];
    }

    public function influenceOf(string $cardType): int
    {
        if (!isset($this->cardTypes[$cardType])) {
            throw new \RuntimeException("unknown card type {$cardType}");
        }
        return (int) $this->cardTypes[$cardType]['influence'];
    }

    /**
     * Breadth-first hop counts from one town, for the bots' sense of "toward".
     *
     * @return array<string, int> town id => hops, omitting anything unreachable
     */
    public function distancesFrom(string $townId): array
    {
        $seen = [$townId => 0];
        $frontier = [$townId];

        while ($frontier) {
            $next = [];
            foreach ($frontier as $current) {
                foreach ($this->towns[$current]['neighbors'] as $neighbor) {
                    if (!isset($seen[$neighbor])) {
                        $seen[$neighbor] = $seen[$current] + 1;
                        $next[] = $neighbor;
                    }
                }
            }
            $frontier = $next;
        }

        return $seen;
    }

    public function totalInfluence(): int
    {
        $total = 0;
        foreach ($this->deck as $typeId => $quantity) {
            $total += $this->influenceOf($typeId) * $quantity;
        }
        return $total;
    }

    /** The whole map's supply: the largest army the board could ever hold. */
    public function mapSupply(): int
    {
        $total = 0;
        foreach ($this->towns as $town) {
            $total += $town['supply'];
        }
        return $total;
    }

    public function deckSize(): int
    {
        return array_sum($this->deck);
    }

    /**
     * Insurgency turns before the deck runs out. The whole hand must be placed
     * every turn (Decision 6), so this is exact, not an estimate.
     */
    public function turns(): int
    {
        return intdiv($this->deckSize(), $this->handSize);
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("cannot parse {$path}: " . json_last_error_msg());
        }
        return $decoded;
    }
}
