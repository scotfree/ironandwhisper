<?php
/**
 * Iron and Whisper — the rules, as pure functions.
 *
 * This is a port of the pure logic in sim/engine.py. It touches no database, no
 * framework services and no notifications: it takes plain arrays describing the
 * board and returns either an answer or a *plan* of changes for the caller to
 * persist. That is what lets it be tested without BGA, and what keeps the
 * comparison against the simulator honest.
 *
 * Board representation — an array keyed by town id, each entry:
 *   [
 *     'neighbors' => string[],
 *     'troops'    => int,
 *     'resolved'  => bool,
 *     'pile'      => face-down cards, list of ['id', 'type', 'influence'],
 *     'revealed'  => face-up cards beside the pile, same shape,
 *   ]
 * Pile index 0 is the TOP. New cards are placed on top; a look flips the top
 * card into `revealed`, where it stays. Both count at resolution.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper;

final class Rules
{
    public const EMPIRE = 'empire';
    public const INSURGENCY = 'insurgency';

    public static function otherSide(string $side): string
    {
        return $side === self::EMPIRE ? self::INSURGENCY : self::EMPIRE;
    }

    // -- reading the board --------------------------------------------------

    /**
     * Everything committed to a town, face down and face up alike. Turning a
     * card face up tells the Empire what it is; it does not take it out of the
     * fight.
     *
     * @param array{pile: array, revealed: array} $town
     */
    public static function townInfluence(array $town): int
    {
        $total = 0;
        foreach (array_merge($town['pile'], $town['revealed']) as $card) {
            $total += (int) $card['influence'];
        }
        return $total;
    }

    /** @param array{pile: array, revealed: array} $town */
    public static function townCardCount(array $town): int
    {
        return count($town['pile']) + count($town['revealed']);
    }

    public static function townStrength(int $troops, int $unitStrength): int
    {
        return $troops * $unitStrength;
    }

    /** @param array<string, array> $towns */
    public static function unresolvedTownIds(array $towns): array
    {
        $ids = [];
        foreach ($towns as $townId => $town) {
            if (!$town['resolved']) {
                $ids[] = $townId;
            }
        }
        return $ids;
    }

    /**
     * The Empire's supply networks.
     *
     * A town is in a network if the Empire stands in it, and two occupied towns
     * are linked if the map links them. Resolution is irrelevant: a town the
     * Empire won and still garrisons carries supply like any other. Cutting a
     * network in two leaves two smaller ceilings, which is the whole reason the
     * Insurgency attacks a junction.
     *
     * @param array<string, array> $towns
     * @return array<int, string[]>
     */
    public static function components(array $towns): array
    {
        $occupied = [];
        foreach ($towns as $townId => $town) {
            if ($town['troops'] > 0) {
                $occupied[$townId] = true;
            }
        }

        $seen = [];
        $components = [];

        foreach (array_keys($occupied) as $townId) {
            if (isset($seen[$townId])) {
                continue;
            }

            $component = [];
            $frontier = [$townId];
            $seen[$townId] = true;

            while ($frontier) {
                $current = array_pop($frontier);
                $component[] = $current;
                foreach ($towns[$current]['neighbors'] as $neighbor) {
                    if (isset($occupied[$neighbor]) && !isset($seen[$neighbor])) {
                        $seen[$neighbor] = true;
                        $frontier[] = $neighbor;
                    }
                }
            }

            sort($component);
            $components[] = $component;
        }

        return $components;
    }

    /**
     * @param array<string, array> $towns
     * @return string[] the network containing this town, empty if it holds none
     */
    public static function componentOf(array $towns, string $townId): array
    {
        foreach (self::components($towns) as $component) {
            if (in_array($townId, $component, true)) {
                return $component;
            }
        }
        return [];
    }

    /**
     * How many troops a network can keep standing.
     *
     * @param array<string, array> $towns
     * @param string[] $component
     */
    public static function ceiling(array $towns, array $component, int $supplyPerTroop): int
    {
        if ($supplyPerTroop <= 0) {
            return 0;
        }
        $supply = 0;
        foreach ($component as $townId) {
            $supply += (int) $towns[$townId]['supply'];
        }
        return intdiv($supply, $supplyPerTroop);
    }

    /**
     * @param array<string, array> $towns
     * @param string[] $component
     */
    public static function troopsIn(array $towns, array $component): int
    {
        $troops = 0;
        foreach ($component as $townId) {
            $troops += (int) $towns[$townId]['troops'];
        }
        return $troops;
    }

    /**
     * Towns the Empire holds that can build and can afford to.
     *
     * @param array<string, array> $towns
     * @return string[]
     */
    public static function productionSites(array $towns, int $productionCost): array
    {
        $sites = [];
        foreach ($towns as $townId => $town) {
            if ($town['troops'] > 0 && self::productionCapacity($towns, $townId, $productionCost) > 0) {
                $sites[] = $townId;
            }
        }
        return $sites;
    }

    /**
     * How many troops a town could raise this turn, ignoring the ceiling.
     *
     * @param array<string, array> $towns
     */
    public static function productionCapacity(array $towns, string $townId, int $productionCost): int
    {
        if (!isset($towns[$townId]) || $towns[$townId]['troops'] === 0 || $productionCost <= 0) {
            return 0;
        }
        return intdiv((int) $towns[$townId]['production'], $productionCost);
    }

    /**
     * Spare ceiling in the network this town belongs to.
     *
     * @param array<string, array> $towns
     */
    public static function headroom(array $towns, string $townId, int $supplyPerTroop): int
    {
        $component = self::componentOf($towns, $townId);
        if (!$component) {
            return 0;
        }
        return max(
            0,
            self::ceiling($towns, $component, $supplyPerTroop) - self::troopsIn($towns, $component),
        );
    }

    /**
     * Which troops starve, because their network can no longer supply them.
     *
     * The Empire's own choices are honoured first, then the largest garrisons,
     * so a turn is always legal even when it names nowhere.
     *
     * @param array<string, array> $towns
     * @param array<string, int> $disband  the Empire's preferences
     * @return array<string, int> town id => troops lost
     */
    public static function attritionPlan(array $towns, int $supplyPerTroop, array $disband = []): array
    {
        $losses = [];

        foreach (self::components($towns) as $component) {
            $over = self::troopsIn($towns, $component)
                - self::ceiling($towns, $component, $supplyPerTroop);
            if ($over <= 0) {
                continue;
            }

            $order = [];
            foreach ($component as $townId) {
                if (($disband[$townId] ?? 0) > 0) {
                    $order[] = $townId;
                }
            }
            $rest = $component;
            usort($rest, static fn(string $a, string $b) => $towns[$b]['troops'] <=> $towns[$a]['troops']);
            $order = array_merge($order, $rest);

            $taken = 0;
            foreach ($order as $townId) {
                if ($taken >= $over) {
                    break;
                }
                $available = (int) $towns[$townId]['troops'] - ($losses[$townId] ?? 0);
                $wanted = isset($disband[$townId]) && !in_array($townId, $rest, true)
                    ? $disband[$townId]
                    : $available;
                $take = min($available, $over - $taken, max(0, $wanted));
                if ($take > 0) {
                    $losses[$townId] = ($losses[$townId] ?? 0) + $take;
                    $taken += $take;
                }
            }
        }

        return $losses;
    }

    /**
     * The presence requirement (Decision 5): you may only resolve a town you
     * are actually in. Without it the Empire freezes empty towns from anywhere
     * for free.
     *
     * @param array<string, array> $towns
     */
    public static function canDeclare(array $towns, string $townId, string $side): bool
    {
        if (!isset($towns[$townId])) {
            return false;
        }
        $town = $towns[$townId];
        if ($town['resolved']) {
            return false;
        }
        return $side === self::EMPIRE
            ? $town['troops'] > 0
            : self::townCardCount($town) > 0;
    }

    /**
     * @param array<string, array> $towns
     * @return string[]
     */
    public static function legalResolutions(array $towns, string $side): array
    {
        $ids = [];
        foreach (self::unresolvedTownIds($towns) as $townId) {
            if (self::canDeclare($towns, $townId, $side)) {
                $ids[] = $townId;
            }
        }
        return $ids;
    }

    // -- resolution ---------------------------------------------------------

    /**
     * Who takes a town, and what the winner scores.
     *
     * You score only what you take off the opponent: the Empire banks the
     * influence it suppressed, the Insurgency banks the strength it absorbed.
     * The Empire wins ties (Decision 7).
     *
     * @return array{winner: string, influence: int, strength: int, points: int}
     */
    public static function resolutionOutcome(int $influence, int $strength, bool $empireWinsTies): array
    {
        if ($strength > $influence) {
            $winner = self::EMPIRE;
        } elseif ($influence > $strength) {
            $winner = self::INSURGENCY;
        } else {
            $winner = $empireWinsTies ? self::EMPIRE : self::INSURGENCY;
        }

        return [
            'winner' => $winner,
            'influence' => $influence,
            'strength' => $strength,
            'points' => $winner === self::EMPIRE ? $influence : $strength,
        ];
    }

    /**
     * Resolution outcome for one town on the given board.
     *
     * @param array<string, array> $towns
     * @return array{winner: string, influence: int, strength: int, points: int}
     */
    public static function resolveTown(array $towns, string $townId, int $unitStrength, bool $empireWinsTies): array
    {
        if (!isset($towns[$townId])) {
            throw new IllegalMove("unknown town {$townId}");
        }
        if ($towns[$townId]['resolved']) {
            throw new IllegalMove("{$townId} is already resolved");
        }

        return self::resolutionOutcome(
            self::townInfluence($towns[$townId]),
            self::townStrength((int) $towns[$townId]['troops'], $unitStrength),
            $empireWinsTies,
        );
    }

    // -- the Insurgency turn ------------------------------------------------

    /**
     * Validate a hand assignment.
     *
     * The entire hand must be placed every turn (Decision 6). That is what
     * makes pile height uninformative and what makes the deck an exact clock,
     * so it is enforced here rather than left to the interface.
     *
     * @param array<string, array> $towns
     * @param int[] $hand              card ids currently in hand
     * @param array<string, int[]> $placements  town id => card ids
     */
    public static function validatePlacements(array $towns, array $hand, array $placements): void
    {
        $placed = [];
        foreach ($placements as $townId => $cardIds) {
            if (!isset($towns[$townId])) {
                throw new IllegalMove("unknown town {$townId}");
            }
            if ($towns[$townId]['resolved']) {
                throw new IllegalMove("{$townId} is resolved; no cards may be placed there");
            }
            foreach ($cardIds as $cardId) {
                $placed[] = (int) $cardId;
            }
        }

        $sortedPlaced = $placed;
        $sortedHand = array_map('intval', $hand);
        sort($sortedPlaced);
        sort($sortedHand);

        if ($sortedPlaced !== $sortedHand) {
            throw new IllegalMove(sprintf(
                'the entire hand must be placed exactly once: got [%s], hand is [%s]',
                implode(',', $sortedPlaced),
                implode(',', $sortedHand),
            ));
        }
    }

    // -- the Empire turn ----------------------------------------------------

    /**
     * Check a set of builds: presence, the town's own production, and the spare
     * ceiling of each network taken together rather than town by town.
     *
     * @param array<string, array> $towns
     * @param array<string, int> $produce town id => troops to raise there
     */
    public static function validateProduction(
        array $towns,
        array $produce,
        int $productionCost,
        int $supplyPerTroop,
    ): void {
        $addedTo = [];

        foreach ($produce as $townId => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                throw new IllegalMove('build count must be positive');
            }
            if (!isset($towns[$townId])) {
                throw new IllegalMove("unknown town {$townId}");
            }
            if ($towns[$townId]['troops'] === 0) {
                throw new IllegalMove("cannot build at {$townId}: no Empire presence");
            }

            $capacity = self::productionCapacity($towns, $townId, $productionCost);
            if ($count > $capacity) {
                throw new IllegalMove("{$townId} can build {$capacity}, asked for {$count}");
            }

            // Several towns can share one ceiling, so charge them all to it.
            $key = implode(',', self::componentOf($towns, $townId));
            $addedTo[$key] = ($addedTo[$key] ?? 0) + $count;
        }

        foreach ($addedTo as $key => $added) {
            $component = explode(',', $key);
            $spare = self::ceiling($towns, $component, $supplyPerTroop)
                - self::troopsIn($towns, $component);
            if ($added > $spare) {
                throw new IllegalMove(
                    "cannot build {$added}: supply supports " . max(0, $spare) . ' more'
                );
            }
        }
    }

    /**
     * Work out the net troop movement, checking adjacency and supply.
     *
     * Movement is simultaneous: everyone leaves, then everyone arrives. Resolved
     * towns are ordinary terrain — pacified, passable, simply no longer
     * contestable — so troops move freely in and out of them.
     *
     * @param array<string, array> $towns
     * @param array<int, array{0: string, 1: string, 2: int}> $moves  [from, to, count]
     * @return array{departures: array<string, int>, arrivals: array<string, int>}
     */
    public static function planMoves(array $towns, array $moves): array
    {
        $departures = [];
        $arrivals = [];

        foreach ($moves as [$from, $to, $count]) {
            $count = (int) $count;
            if ($count <= 0) {
                throw new IllegalMove('move count must be positive');
            }
            if (!isset($towns[$from]) || !isset($towns[$to])) {
                throw new IllegalMove("unknown town in move {$from} -> {$to}");
            }
            if (!in_array($to, $towns[$from]['neighbors'], true)) {
                throw new IllegalMove("{$to} is not adjacent to {$from}");
            }

            $departures[$from] = ($departures[$from] ?? 0) + $count;
            if ($departures[$from] > $towns[$from]['troops']) {
                throw new IllegalMove(sprintf(
                    '%s has %d troops, tried to move %d',
                    $from,
                    $towns[$from]['troops'],
                    $departures[$from],
                ));
            }
            $arrivals[$to] = ($arrivals[$to] ?? 0) + $count;
        }

        return ['departures' => $departures, 'arrivals' => $arrivals];
    }

    /**
     * How many cards each town's pile gets peeked at.
     *
     * Every troop that did not move looks; peeks stack per town. There is no
     * decision in it — looking is free, always available to a stationary troop,
     * and never disadvantageous — so it happens automatically at the end of the
     * move step rather than as a prompt.
     *
     * `$towns` must already reflect generation and movement. A troop raised
     * this turn counts as stationary: it did not move.
     *
     * A town whose pile is entirely face up has nothing left to read, so it is
     * skipped: the pile only ever holds cards nobody has seen.
     *
     * @param array<string, array> $towns    board after generation and movement
     * @param array<string, int> $arrivals   from planMoves()
     * @return array<string, int>            town id => number of cards to look at
     */
    public static function peekPlan(array $towns, array $arrivals, int $peekPerTroop): array
    {
        $looks = [];
        foreach ($towns as $townId => $town) {
            if ($town['resolved'] || count($town['pile']) === 0) {
                continue;
            }
            $stationary = (int) $town['troops'] - ($arrivals[$townId] ?? 0);
            if ($stationary <= 0) {
                continue;
            }
            $looks[$townId] = $stationary * $peekPerTroop;
        }
        return $looks;
    }

    /**
     * Flip the top cards of the face-down pile face up (Decision 8).
     *
     * There is no rotation and nothing to cap. The pile holds only cards nobody
     * has seen, so a look can never land on one that is already known, and when
     * the pile is empty the garrison has read the town.
     *
     * @param array<int, array{id: int}> $pile  face-down cards, index 0 = top
     * @return int[]                            ids of the cards turned face up
     */
    public static function revealFromPile(array $pile, int $lookCount): array
    {
        return array_map(
            static fn(array $card) => (int) $card['id'],
            array_slice(array_values($pile), 0, max(0, $lookCount)),
        );
    }
}
