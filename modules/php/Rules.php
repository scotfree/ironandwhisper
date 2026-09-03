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
     * Where the Empire may raise troops.
     *
     * Normally: any town it already stands in. Resolved towns do not qualify —
     * the garrison there was spent, so the Empire no longer holds the place.
     *
     * The fallback exists because troops are consumed by resolution: an Empire
     * that commits its last troops would otherwise have no legal generation
     * site and be eliminated with turns still on the clock. With nothing on the
     * board it may raise troops in any town still contested.
     *
     * @param array<string, array> $towns
     * @return string[]
     */
    public static function legalGenerationTowns(array $towns): array
    {
        $held = [];
        foreach ($towns as $townId => $town) {
            if ($town['troops'] > 0) {
                $held[] = $townId;
            }
        }
        return $held ?: self::unresolvedTownIds($towns);
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
     * @param array<string, array> $towns
     */
    public static function validateGeneration(array $towns, ?string $townId): void
    {
        if ($townId === null) {
            return;
        }
        if (!isset($towns[$townId])) {
            throw new IllegalMove("unknown town {$townId}");
        }
        if (!in_array($townId, self::legalGenerationTowns($towns), true)) {
            throw new IllegalMove("cannot generate at {$townId}: no Empire presence");
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
