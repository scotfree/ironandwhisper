<?php
/**
 * Iron and Whisper — the bots.
 *
 * A port of the heuristics in sim/bots.py. Like Rules, this touches no
 * database and no framework: it takes the board and returns the same turn
 * structures a human's client would send, so a bot turn goes through exactly
 * the same validation and notifications as a person's.
 *
 * The Empire bot may only use information the Empire is entitled to. That is
 * not a courtesy — it is the whole reason the estimate is interesting — so it
 * reads `revealed` and pile *heights* and never looks inside a face-down pile.
 * `belief()` below is the only place hidden state could leak in, which makes it
 * the only place to check.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper;

final class Bots
{
    /** Don't resolve for fewer points than this. */
    private const INSURGENCY_MIN_SCORE = 3;

    /** Overshoot the Empire's strength by this much when committing. */
    private const INSURGENCY_MARGIN = 1;

    /** How many towns to divide influence across each turn. */
    private const INSURGENCY_SPREAD = 1;

    /** Required ratio of strength to estimated influence before resolving. */
    private const EMPIRE_CONFIDENCE = 1.15;

    /** Don't resolve for fewer estimated points than this. */
    private const EMPIRE_MIN_SCORE = 2.0;

    // -- what the Empire is allowed to think -------------------------------

    /**
     * The rate at which an unseen card is worth influence.
     *
     * Every card is either face up beside its town or face down in a pile. The
     * deck's composition is public, so the expected value of anything still
     * face down is the ratio across everything unaccounted for.
     *
     * @param array<string, array> $towns
     * @return array{rate: float}
     */
    public static function belief(Scenario $scenario, array $towns): array
    {
        $knownInfluence = 0;
        $knownCount = 0;
        foreach ($towns as $town) {
            foreach ($town['revealed'] as $card) {
                $knownInfluence += (int) $card['influence'];
                $knownCount++;
            }
        }

        $unknownCount = $scenario->deckSize() - $knownCount;
        $unknownInfluence = $scenario->totalInfluence() - $knownInfluence;

        return ['rate' => $unknownCount > 0 ? $unknownInfluence / $unknownCount : 0.0];
    }

    /**
     * Best guess at what a town is really worth, from Empire-legal information.
     *
     * @param array{rate: float} $belief
     * @param array{pile: array, revealed: array} $town
     */
    public static function estimate(array $belief, array $town): float
    {
        $known = 0;
        foreach ($town['revealed'] as $card) {
            $known += (int) $card['influence'];
        }
        return $known + count($town['pile']) * $belief['rate'];
    }

    // -- the Insurgency ----------------------------------------------------

    /**
     * Concentrate influence where the Empire has committed; scatter dummies as
     * noise, preferring towns the Empire is standing in or beside so the bluff
     * invites over-commitment.
     *
     * @param array<string, array> $towns
     * @param array<int, array{id: int, influence: int}> $hand
     * @return array{placements: array<string, int[]>, resolve: ?string}
     */
    public static function insurgencyTurn(Scenario $scenario, array $towns, array $hand): array
    {
        $open = [];
        foreach ($towns as $townId => $town) {
            if (!$town['resolved']) {
                $open[$townId] = $town;
            }
        }
        if (!$open) {
            return ['placements' => [], 'resolve' => null];
        }

        // Cards are graded, so commit by value rather than by count: spending
        // three ones where a three would do wastes two cards. Biggest first
        // reaches a threshold with the fewest cards, leaving more for elsewhere.
        $influence = [];
        $worthless = [];
        $valueOf = [];
        foreach ($hand as $card) {
            $valueOf[(int) $card['id']] = (int) $card['influence'];
            if ((int) $card['influence'] > 0) {
                $influence[] = (int) $card['id'];
            } else {
                $worthless[] = (int) $card['id'];
            }
        }
        usort($influence, static fn(int $a, int $b) => $valueOf[$b] <=> $valueOf[$a]);

        $strengthOf = static fn(array $town): int
            => Rules::townStrength((int) $town['troops'], $scenario->unitStrength());

        // Garrisoned towns we could plausibly flip, richest first.
        $targets = array_filter($open, static fn(array $town) => $town['troops'] > 0);
        uasort($targets, static fn(array $a, array $b) => $strengthOf($b) <=> $strengthOf($a));
        $targets = array_slice(array_keys($targets), 0, max(1, self::INSURGENCY_SPREAD));

        $placements = [];
        foreach ($targets as $townId) {
            if (!$influence) {
                break;
            }
            $needed = $strengthOf($open[$townId])
                - Rules::townInfluence($open[$townId])
                + self::INSURGENCY_MARGIN;

            $chosen = [];
            $committed = 0;
            while ($influence && $committed < $needed) {
                $cardId = array_shift($influence);
                $chosen[] = $cardId;
                $committed += $valueOf[$cardId];
            }
            if ($chosen) {
                $placements[$townId] = $chosen;
            }
        }

        // Leftover influence goes where the Empire is likeliest to arrive.
        if ($influence) {
            $fallback = $targets[0] ?? array_rand($open);
            $placements[$fallback] = array_merge($placements[$fallback] ?? [], $influence);
        }

        // Worthless cards go next to troops, so the noise looks like something.
        $bait = [];
        foreach ($open as $townId => $town) {
            if ($town['troops'] > 0) {
                $bait[] = $townId;
                continue;
            }
            foreach ($town['neighbors'] as $neighbor) {
                if (!$towns[$neighbor]['resolved'] && $towns[$neighbor]['troops'] > 0) {
                    $bait[] = $townId;
                    break;
                }
            }
        }
        $bait = $bait ?: array_keys($open);

        foreach ($worthless as $cardId) {
            $townId = $bait[array_rand($bait)];
            $placements[$townId][] = $cardId;
        }

        return [
            'placements' => $placements,
            'resolve' => self::insurgencyResolution($scenario, $open, $hand, $placements),
        ];
    }

    /**
     * Resolve where the placement just made wins, and the prize is worth taking.
     *
     * @param array<string, array> $open
     * @param array<int, array{id: int, influence: int}> $hand
     * @param array<string, int[]> $placements
     */
    private static function insurgencyResolution(
        Scenario $scenario,
        array $open,
        array $hand,
        array $placements,
    ): ?string {
        $influenceOfCard = [];
        foreach ($hand as $card) {
            $influenceOfCard[(int) $card['id']] = (int) $card['influence'];
        }

        $resolve = null;
        $best = self::INSURGENCY_MIN_SCORE - 1;

        foreach ($open as $townId => $town) {
            $influence = Rules::townInfluence($town);
            foreach ($placements[$townId] ?? [] as $cardId) {
                $influence += $influenceOfCard[$cardId] ?? 0;
            }
            $strength = Rules::townStrength((int) $town['troops'], $scenario->unitStrength());

            // Only worth cashing if we beat the garrison, and the garrison was
            // worth beating: the Insurgency scores the strength it overcomes.
            if ($influence > $strength && $strength > $best) {
                $best = $strength;
                $resolve = $townId;
            }
        }

        return $resolve;
    }

    // -- the Empire --------------------------------------------------------

    /**
     * March toward tall piles, hold where we already look like we are winning,
     * and resolve when the estimate says we win by enough.
     *
     * @param array<string, array> $towns
     * @return array{generateAt: ?string, moves: array<int, array{from: string, to: string, count: int}>, resolve: ?string}
     */
    public static function empireTurn(Scenario $scenario, array $towns): array
    {
        $belief = self::belief($scenario, $towns);
        $estimateOf = [];
        foreach ($towns as $townId => $town) {
            $estimateOf[$townId] = self::estimate($belief, $town);
        }

        $open = [];
        foreach ($towns as $townId => $town) {
            if (!$town['resolved']) {
                $open[] = $townId;
            }
        }

        $generateAt = self::empireGeneration($scenario, $towns, $open, $estimateOf);
        $moves = self::empireMoves($scenario, $towns, $estimateOf);

        return [
            'generateAt' => $generateAt,
            'moves' => $moves,
            'resolve' => self::empireResolution($scenario, $towns, $open, $estimateOf, $generateAt, $moves),
        ];
    }

    /**
     * Raise where the fighting is: the legal anchor nearest the fattest pile.
     *
     * @param array<string, array> $towns
     * @param string[] $open
     * @param array<string, float> $estimateOf
     */
    private static function empireGeneration(
        Scenario $scenario,
        array $towns,
        array $open,
        array $estimateOf,
    ): ?string {
        $anchors = Rules::legalGenerationTowns($towns);
        if (!$anchors) {
            return null;
        }
        if (!$open) {
            return $anchors[array_rand($anchors)];
        }

        $hot = $open[0];
        foreach ($open as $townId) {
            if ($estimateOf[$townId] > $estimateOf[$hot]) {
                $hot = $townId;
            }
        }

        $distances = $scenario->distancesFrom($hot);
        $nearest = $anchors[0];
        foreach ($anchors as $anchor) {
            if (($distances[$anchor] ?? 99) < ($distances[$nearest] ?? 99)) {
                $nearest = $anchor;
            }
        }
        return $nearest;
    }

    /**
     * @param array<string, array> $towns
     * @param array<string, float> $estimateOf
     * @return array<int, array{from: string, to: string, count: int}>
     */
    private static function empireMoves(Scenario $scenario, array $towns, array $estimateOf): array
    {
        $moves = [];

        foreach ($towns as $townId => $town) {
            if ($town['troops'] === 0 || !$town['neighbors']) {
                continue;
            }

            $best = $town['neighbors'][0];
            foreach ($town['neighbors'] as $neighbor) {
                if ($estimateOf[$neighbor] > $estimateOf[$best]) {
                    $best = $neighbor;
                }
            }

            // A resolved town has nothing left to win: march toward what does.
            if ($town['resolved']) {
                $moves[] = ['from' => $townId, 'to' => $best, 'count' => $town['troops']];
                continue;
            }

            $estimate = $estimateOf[$townId];
            $strength = Rules::townStrength((int) $town['troops'], $scenario->unitStrength());
            if ($estimate > 0 && $strength >= $estimate * self::EMPIRE_CONFIDENCE) {
                continue; // hold: we think we already win here
            }
            if ($estimateOf[$best] > $estimate) {
                $moves[] = ['from' => $townId, 'to' => $best, 'count' => $town['troops']];
            }
        }

        return $moves;
    }

    /**
     * Judge resolutions against where the troops will BE, not where they are:
     * generation and movement both happen first (Decision 4).
     *
     * @param array<string, array> $towns
     * @param string[] $open
     * @param array<string, float> $estimateOf
     * @param array<int, array{from: string, to: string, count: int}> $moves
     */
    private static function empireResolution(
        Scenario $scenario,
        array $towns,
        array $open,
        array $estimateOf,
        ?string $generateAt,
        array $moves,
    ): ?string {
        $projected = [];
        foreach ($towns as $townId => $town) {
            $projected[$townId] = (int) $town['troops'];
        }
        if ($generateAt !== null) {
            $projected[$generateAt] += $scenario->generationRate;
        }
        foreach ($moves as $move) {
            $projected[$move['from']] -= $move['count'];
            $projected[$move['to']] += $move['count'];
        }

        $resolve = null;
        $best = -1.0;

        foreach ($open as $townId) {
            if ($projected[$townId] <= 0) {
                continue;
            }
            $estimate = $estimateOf[$townId];
            $strength = Rules::townStrength($projected[$townId], $scenario->unitStrength());
            if ($strength < $estimate * self::EMPIRE_CONFIDENCE) {
                continue;
            }
            if ($estimate < self::EMPIRE_MIN_SCORE) {
                continue;
            }
            if ($estimate > $best) {
                $best = $estimate;
                $resolve = $townId;
            }
        }

        return $resolve;
    }
}
