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

        // Resolution happens first in the turn (Decision 4), so it is decided
        // against the board as it stands, and a town resolved now cannot then
        // be placed in.
        $resolve = self::insurgencyResolution($scenario, $open);
        if ($resolve !== null) {
            unset($open[$resolve]);
        }
        if (!$open) {
            return ['placements' => [], 'resolve' => $resolve];
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

        return ['placements' => $placements, 'resolve' => $resolve];
    }

    /**
     * Cash a town we have already beaten, if the garrison is worth taking.
     *
     * @param array<string, array> $open
     */
    private static function insurgencyResolution(Scenario $scenario, array $open): ?string
    {
        $resolve = null;
        $best = self::INSURGENCY_MIN_SCORE - 1;

        foreach ($open as $townId => $town) {
            if (Rules::townCardCount($town) === 0) {
                continue;
            }
            $influence = Rules::townInfluence($town);
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

        // Resolution happens first in the turn (Decision 4), so it is judged on
        // the troops standing now — there is no marching in and cashing out.
        $resolve = self::empireResolution($scenario, $towns, $open, $estimateOf);

        // A person resolves in a phase of its own and sees the result before
        // deciding anything else, so they may march a garrison out of a town
        // they just won. A bot submits its whole turn in one call and cannot
        // know how the fight went — and if it loses, those troops are gone
        // before the march, which makes the turn illegal. So it holds still
        // where it is fighting.
        $moves = array_values(array_filter(
            self::empireMoves($scenario, $towns, $estimateOf),
            static fn(array $move) => $move['from'] !== $resolve,
        ));

        return [
            'produce' => self::empireProduction($scenario, $towns),
            'moves' => $moves,
            'resolve' => $resolve,
        ];
    }

    /**
     * Build wherever there is capacity and supply for it.
     *
     * There is rarely a reason to leave capacity idle, so this takes what each
     * site offers until the network's ceiling runs out. Sites in the same
     * network share one ceiling, which is why the spare is tracked per network
     * rather than per town.
     *
     * @param array<string, array> $towns
     * @return array<string, int>
     */
    private static function empireProduction(Scenario $scenario, array $towns): array
    {
        $produce = [];
        $spare = [];

        foreach (Rules::productionSites($towns, $scenario->productionCost) as $site) {
            $component = Rules::componentOf($towns, $site);
            if (!$component) {
                // Nobody is standing here, so there is no network to overload —
                // and building is the only way back onto the board at all. The
                // troop brings the town's own supply with it.
                $produce[$site] = Rules::productionCapacity(
                    $towns,
                    $site,
                    $scenario->productionCost,
                );
                continue;
            }

            $key = implode(',', $component);
            if (!isset($spare[$key])) {
                // Building past the ceiling is legal; this bot declines to,
                // because nothing in it plans a march out to the supply that
                // would feed the overshoot. A policy, not a rule.
                $spare[$key] = Rules::headroom($towns, $site, $scenario->supplyPerTroop);
            }

            $want = min(
                Rules::productionCapacity($towns, $site, $scenario->productionCost),
                $spare[$key],
            );
            if ($want > 0) {
                $produce[$site] = $want;
                $spare[$key] -= $want;
            }
        }

        return $produce;
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
     * Cash a town we already hold and believe we win, before doing anything
     * else this turn (Decision 4).
     *
     * @param array<string, array> $towns
     * @param string[] $open
     * @param array<string, float> $estimateOf
     */
    private static function empireResolution(
        Scenario $scenario,
        array $towns,
        array $open,
        array $estimateOf,
    ): ?string {
        $resolve = null;
        $best = -1.0;

        foreach ($open as $townId) {
            if ($towns[$townId]['troops'] <= 0) {
                continue;
            }
            $estimate = $estimateOf[$townId];
            $strength = Rules::townStrength((int) $towns[$townId]['troops'], $scenario->unitStrength());
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
