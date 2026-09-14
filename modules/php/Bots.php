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

    /** Overshoot the Empire's presence by this much when committing. */
    private const INSURGENCY_MARGIN = 1;

    /** How many towns to divide presence across each turn. */
    private const INSURGENCY_SPREAD = 1;

    /** Required ratio of troop presence to estimated card presence before resolving. */
    private const EMPIRE_CONFIDENCE = 1.15;

    /** Don't resolve for fewer estimated points than this. */
    private const EMPIRE_MIN_SCORE = 2.0;

    /**
     * How far behind a garrison may fall before GlobEmpire withdraws it.
     *
     * Large on purpose. `globWorstCase` assumes every face-down card is the
     * best in the deck, and at baseline the deck is 40% bluffs, so a pile that
     * *could* beat a garrison by two usually does not. A sweep over 500 games
     * per setting puts the Empire at 44% at a margin of 3, 64% at 5, 66% at 6
     * and back to 46% at 14 — a broad plateau rather than the cliffs these
     * sweeps usually find. Retreating too eagerly hands towns to bluffs; never
     * retreating feeds garrisons into piles that really were tall.
     */
    private const GLOB_RETREAT_MARGIN = 5;

    // -- what the Empire is allowed to think -------------------------------

    /**
     * The rate at which an unseen card is worth presence.
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
        $knownPresence = 0;
        $knownCount = 0;
        foreach ($towns as $town) {
            foreach ($town['revealed'] as $card) {
                $knownPresence += (int) $card['presence'];
                $knownCount++;
            }
        }

        $unknownCount = $scenario->deckSize() - $knownCount;
        $unknownPresence = $scenario->totalCardPresence() - $knownPresence;

        return ['rate' => $unknownCount > 0 ? $unknownPresence / $unknownCount : 0.0];
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
            $known += (int) $card['presence'];
        }
        return $known + count($town['pile']) * $belief['rate'];
    }

    // -- the Insurgency ----------------------------------------------------

    /**
     * Concentrate presence where the Empire has committed; scatter dummies as
     * noise, preferring towns the Empire is standing in or beside so the bluff
     * invites over-commitment.
     *
     * @param array<string, array> $towns
     * @param array<int, array{id: int, presence: int}> $hand
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
        $presence = [];
        $worthless = [];
        $valueOf = [];
        foreach ($hand as $card) {
            $valueOf[(int) $card['id']] = (int) $card['presence'];
            if ((int) $card['presence'] > 0) {
                $presence[] = (int) $card['id'];
            } else {
                $worthless[] = (int) $card['id'];
            }
        }
        usort($presence, static fn(int $a, int $b) => $valueOf[$b] <=> $valueOf[$a]);

        $troopPresenceOf = static fn(array $town): int
            => Rules::troopPresence((int) $town['troops'], $scenario->unitPresence());

        // Garrisoned towns we could plausibly flip, richest first.
        $targets = array_filter($open, static fn(array $town) => $town['troops'] > 0);
        uasort($targets, static fn(array $a, array $b) => $troopPresenceOf($b) <=> $troopPresenceOf($a));
        $targets = array_slice(array_keys($targets), 0, max(1, self::INSURGENCY_SPREAD));

        $placements = [];
        foreach ($targets as $townId) {
            if (!$presence) {
                break;
            }
            $needed = $troopPresenceOf($open[$townId])
                - Rules::cardPresence($open[$townId])
                + self::INSURGENCY_MARGIN;

            $chosen = [];
            $committed = 0;
            while ($presence && $committed < $needed) {
                $cardId = array_shift($presence);
                $chosen[] = $cardId;
                $committed += $valueOf[$cardId];
            }
            if ($chosen) {
                $placements[$townId] = $chosen;
            }
        }

        // Leftover presence goes where the Empire is likeliest to arrive.
        if ($presence) {
            $fallback = $targets[0] ?? array_rand($open);
            $placements[$fallback] = array_merge($placements[$fallback] ?? [], $presence);
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
            $presence = Rules::cardPresence($town);
            $troopPresence = Rules::troopPresence((int) $town['troops'], $scenario->unitPresence());

            // Only worth cashing if we beat the garrison, and the garrison was
            // worth beating: the Insurgency scores the presence it overcomes.
            if ($presence > $troopPresence && $troopPresence > $best) {
                $best = $troopPresence;
                $resolve = $townId;
            }
        }

        return $resolve;
    }

    // -- the Insurgency that plays the map ---------------------------------

    /**
     * MistBot: read the board rather than the arithmetic.
     *
     * `insurgencyTurn` above picks the richest garrison it can see and piles
     * presence onto it. This one asks three questions in order, and every
     * answer is about *geography* — where the troops are, not how many points
     * are on the table:
     *
     * - Resolve anything already won, richest first. The rebels placed every
     *   card and troops are public, so a win is certain by inspection: there
     *   is no worst case here and nothing to gamble on. Ties inside a score go
     *   to the town touching the most occupied towns, because that is the one
     *   the Empire is likeliest to reinforce before it can be cashed again.
     * - Get ahead where the Empire is thin on support. Spend the fewest cards
     *   that clear the garrison by one, and prefer the town with the fewest
     *   troops *around* it: a lead only survives if the Empire cannot walk a
     *   relief column into it before the next turn.
     * - Spend the bluffs where a pile will be believed, one at a time,
     *   re-reading the board between each. An empty town beside a lot of
     *   troops first, and never an empty town with no troops near it, which
     *   nobody will ever walk into and which therefore says nothing.
     *
     * A direct port of MistBot in sim/bots.py. If the two disagree, this one
     * is wrong. It takes no randomness at all: every tie breaks on the board,
     * so a disagreement with the simulator is a real one rather than a seed.
     *
     * @param array<string, array> $towns
     * @param array<int, array{id: int, presence: int}> $hand
     * @return array{placements: array<string, int[]>, resolve: ?string}
     */
    public static function mistInsurgencyTurn(Scenario $scenario, array $towns, array $hand): array
    {
        // Planned against a copy with the resolution already applied, in the
        // engine's own order (Decision 4): a town resolved now is closed to
        // placement, and a garrison the rebels just took is off the board and
        // out of every adjacency count below.
        $board = $towns;

        $resolve = self::mistResolution($scenario, $board);
        if ($resolve !== null) {
            $board = self::mistApplyResolution($board, $resolve);
        }

        return [
            'placements' => self::mistPlacements($scenario, $board, $hand),
            'resolve' => $resolve,
        ];
    }

    /**
     * Cash every town already won, richest first; a 0-point win counts.
     *
     * Beating an empty garrison scores nothing, but it still takes the town
     * out of the game, and a town the Empire can never stand in is a town it
     * can never draw supply from. The mirror of globResolution taking an empty
     * town for the same reason.
     *
     * @param array<string, array> $towns
     */
    private static function mistResolution(Scenario $scenario, array $towns): ?string
    {
        $best = null;
        $bestKey = null;

        foreach ($towns as $townId => $town) {
            if ($town['resolved'] || Rules::townCardCount($town) === 0) {
                continue;
            }
            $troopPresence = Rules::troopPresence((int) $town['troops'], $scenario->unitPresence());
            if (Rules::cardPresence($town) <= $troopPresence) {
                continue; // the Empire wins ties, so a draw is not a win
            }
            $key = [$troopPresence, self::mistAdjacentOccupied($towns, $town), $townId];
            if ($bestKey === null || $key > $bestKey) {
                $best = $townId;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * The board once the rebels have taken a town: it freezes, the pile turns
     * face up, and the garrison is captured (Decision 3).
     *
     * @param array<string, array> $towns
     * @return array<string, array>
     */
    private static function mistApplyResolution(array $towns, string $townId): array
    {
        $towns[$townId]['resolved'] = true;
        $towns[$townId]['winner'] = Rules::INSURGENCY;
        $towns[$townId]['revealed'] = array_merge(
            $towns[$townId]['revealed'],
            $towns[$townId]['pile'],
        );
        $towns[$townId]['pile'] = [];
        $towns[$townId]['troops'] = 0;
        return $towns;
    }

    /**
     * Where the whole hand goes, deciding one commitment at a time.
     *
     * `$board` is mutated as cards are committed, so every choice after the
     * first sees the pile the last one built. That is what makes the bluff
     * rule spread: a town stops being empty the moment it is seeded.
     *
     * @param array<string, array> $board
     * @param array<int, array{id: int, presence: int}> $hand
     * @return array<string, int[]>
     */
    private static function mistPlacements(Scenario $scenario, array $board, array $hand): array
    {
        $anyOpen = false;
        foreach ($board as $town) {
            if (!$town['resolved']) {
                $anyOpen = true;
                break;
            }
        }
        if (!$anyOpen) {
            return []; // the resolution closed the last town; nothing may be placed
        }

        $placements = [];
        $place = static function (string $townId, int $cardId, int $presence) use (&$placements, &$board): void {
            $placements[$townId][] = $cardId;
            array_unshift($board[$townId]['pile'], [
                'id' => $cardId,
                'type' => "presence{$presence}",
                'presence' => $presence,
                'seen' => false,
            ]);
        };

        // Biggest first: it reaches a threshold with the fewest cards, which
        // leaves the rest of the hand free to threaten somewhere else.
        $valueOf = [];
        $ordered = [];
        $bluffs = [];
        foreach (array_values($hand) as $position => $card) {
            $cardId = (int) $card['id'];
            $valueOf[$cardId] = (int) $card['presence'];
            if ($valueOf[$cardId] > 0) {
                $ordered[] = [$position, $cardId];
            } else {
                $bluffs[] = $cardId;
            }
        }
        usort(
            $ordered,
            static fn(array $a, array $b): int
                => [$valueOf[$b[1]], $a[0]] <=> [$valueOf[$a[1]], $b[0]],
        );
        $real = array_map(static fn(array $entry): int => $entry[1], $ordered);

        // 1. Take the lead in every town the hand can still afford to lead in.
        $bestTarget = null;
        while ($real) {
            $budget = 0;
            foreach ($real as $cardId) {
                $budget += $valueOf[$cardId];
            }
            $target = self::mistLeadTarget($scenario, $board, $budget);
            if ($target === null) {
                break;
            }
            if ($bestTarget === null) {
                $bestTarget = $target;
            }
            $needed = Rules::troopPresence((int) $board[$target]['troops'], $scenario->unitPresence())
                - Rules::cardPresence($board[$target]) + 1;
            while ($real && $needed > 0) {
                $cardId = array_shift($real);
                $needed -= $valueOf[$cardId];
                $place($target, $cardId, $valueOf[$cardId]);
            }
        }

        // 2. Nothing left to flip: seed where the Empire has enough troops
        //    next door to come and make a fight of it. One card each — a
        //    second card on the same town says nothing new.
        while ($real) {
            $target = self::mistArrivalTarget($board);
            if ($target === null) {
                break;
            }
            $cardId = array_shift($real);
            $place($target, $cardId, $valueOf[$cardId]);
        }

        // 3. Anything still in hand deepens the town we led in first.
        if ($bestTarget !== null) {
            while ($real) {
                $cardId = array_shift($real);
                $place($bestTarget, $cardId, $valueOf[$cardId]);
            }
        }

        // 4. Bluffs, and any real card with nowhere better, one at a time.
        foreach (array_merge($real, $bluffs) as $cardId) {
            $place(self::mistBluffTarget($scenario, $board), $cardId, $valueOf[$cardId]);
        }

        return $placements;
    }

    /**
     * The garrisoned town to take the lead in next, or null.
     *
     * Affordable means the presence still in hand covers the whole deficit:
     * half a lead is a donation, since the Empire scores every card in a town
     * it wins.
     *
     * @param array<string, array> $board
     */
    private static function mistLeadTarget(Scenario $scenario, array $board, int $budget): ?string
    {
        $best = null;
        $bestKey = null;

        foreach ($board as $townId => $town) {
            if ($town['resolved'] || $town['troops'] <= 0) {
                continue;
            }
            $troopPresence = Rules::troopPresence((int) $town['troops'], $scenario->unitPresence());
            $deficit = $troopPresence - Rules::cardPresence($town) + 1;
            if ($deficit <= 0 || $deficit > $budget) {
                continue;
            }
            // Fewest troops in reach first — a lead the Empire cannot answer
            // next turn. Then the richest garrison, since that is the score.
            $key = [self::mistAdjacentTroops($board, $town), -$troopPresence, $townId];
            if ($bestKey === null || $key < $bestKey) {
                $best = $townId;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * An empty town next to a garrison big enough to spare a troop.
     *
     * One troop marching out of a town of one abandons it, so a lone troop is
     * not really a neighbour. Two is the smallest garrison that can visit.
     *
     * @param array<string, array> $board
     */
    private static function mistArrivalTarget(array $board): ?string
    {
        $best = null;
        $bestKey = null;

        foreach ($board as $townId => $town) {
            if ($town['resolved'] || $town['troops'] > 0 || Rules::townCardCount($town) > 0) {
                continue;
            }
            $canVisit = false;
            foreach ($town['neighbors'] as $neighbor) {
                if ($board[$neighbor]['troops'] > 1) {
                    $canVisit = true;
                    break;
                }
            }
            if (!$canVisit) {
                continue;
            }
            $key = [-self::mistAdjacentTroops($board, $town), $townId];
            if ($bestKey === null || $key < $bestKey) {
                $best = $townId;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * Where the next single card goes, re-read from the board each time.
     *
     * Empty towns beside troops first, tallest concentration first; then the
     * closest thing to a tied town, where one more card in the pile changes
     * what the Empire believes it is looking at. An empty town with no troops
     * anywhere near it is never chosen — nobody will walk into it, so the
     * bluff is spent on an audience of nobody — unless it is all that is left,
     * in which case the nearest one to a troop takes it.
     *
     * @param array<string, array> $board
     */
    private static function mistBluffTarget(Scenario $scenario, array $board): string
    {
        $best = null;
        $bestKey = null;

        foreach ($board as $townId => $town) {
            if ($town['resolved'] || $town['troops'] > 0 || Rules::townCardCount($town) > 0) {
                continue;
            }
            $adjacent = self::mistAdjacentTroops($board, $town);
            if ($adjacent === 0) {
                continue;
            }
            $key = [-$adjacent, $townId];
            if ($bestKey === null || $key < $bestKey) {
                $best = $townId;
                $bestKey = $key;
            }
        }
        if ($best !== null) {
            return $best;
        }

        // Towns where the two sides are closest: a pile that already looks
        // like a fight is the one an extra card can tip in the Empire's
        // reading.
        foreach ($board as $townId => $town) {
            if ($town['resolved']) {
                continue;
            }
            $adjacent = self::mistAdjacentTroops($board, $town);
            if ($town['troops'] <= 0 && Rules::townCardCount($town) === 0 && $adjacent === 0) {
                continue;
            }
            $gap = abs(
                Rules::cardPresence($town)
                - Rules::troopPresence((int) $town['troops'], $scenario->unitPresence())
            );
            $key = [$gap, -$adjacent, $townId];
            if ($bestKey === null || $key < $bestKey) {
                $best = $townId;
                $bestKey = $key;
            }
        }
        if ($best !== null) {
            return $best;
        }

        // Every open town is empty and out of reach of any troop. Forced, so
        // take the one the Empire would reach first.
        $hops = self::mistHopsToTroops($board);
        foreach ($board as $townId => $town) {
            if ($town['resolved']) {
                continue;
            }
            $key = [$hops[$townId] ?? count($board), $townId];
            if ($bestKey === null || $key < $bestKey) {
                $best = $townId;
                $bestKey = $key;
            }
        }

        return (string) $best;
    }

    /**
     * Troops standing in the towns next door, added up.
     *
     * Resolved towns count: an Empire garrison survives a town it won
     * (Decision 3) and can march out of it like any other.
     *
     * @param array<string, array> $towns
     */
    private static function mistAdjacentTroops(array $towns, array $town): int
    {
        $total = 0;
        foreach ($town['neighbors'] as $neighbor) {
            $total += (int) $towns[$neighbor]['troops'];
        }
        return $total;
    }

    /**
     * How many towns next door hold any troops at all.
     *
     * Deliberately a count of towns where mistAdjacentTroops is a sum of
     * troops: one asks how many directions a threat can come from, the other
     * how heavy it would be.
     *
     * @param array<string, array> $towns
     */
    private static function mistAdjacentOccupied(array $towns, array $town): int
    {
        $count = 0;
        foreach ($town['neighbors'] as $neighbor) {
            if ($towns[$neighbor]['troops'] > 0) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Distance from every town to the nearest troops, by road.
     *
     * @param array<string, array> $towns
     * @return array<string, int>
     */
    private static function mistHopsToTroops(array $towns): array
    {
        $distance = [];
        $frontier = [];
        foreach ($towns as $townId => $town) {
            if ($town['troops'] > 0) {
                $distance[$townId] = 0;
                $frontier[] = $townId;
            }
        }
        while ($frontier) {
            $current = array_shift($frontier);
            foreach ($towns[$current]['neighbors'] as $neighbor) {
                if (!isset($distance[$neighbor])) {
                    $distance[$neighbor] = $distance[$current] + 1;
                    $frontier[] = $neighbor;
                }
            }
        }
        return $distance;
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
            $troopPresence = Rules::troopPresence((int) $town['troops'], $scenario->unitPresence());
            if ($estimate > 0 && $troopPresence >= $estimate * self::EMPIRE_CONFIDENCE) {
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
            $troopPresence = Rules::troopPresence((int) $towns[$townId]['troops'], $scenario->unitPresence());
            if ($troopPresence < $estimate * self::EMPIRE_CONFIDENCE) {
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

    // -- the Empire, playing for the network -------------------------------

    /**
     * Plays for the supply network, and only fights battles it has already won.
     *
     * `empireTurn` above marches at the tallest pile it can find and resolves
     * on a ratio it believes in. This one does neither. It plays the way the
     * game has actually been played well at a table: resolve only a *certain*
     * win, judged against the worst the face-down cards could possibly be; then
     * spread outward in a wave, because the ceiling is what keeps an army alive
     * and a three-card hand cannot contest three new towns at once; and
     * withdraw a garrison that has fallen behind rather than feed it in.
     *
     * The objective is *ceiling*, not points. Points arrive as a by-product of
     * resolving towns it was already safe in — which is why an expansion
     * prefers a seeded town it can beat over an empty one: the supply is the
     * same and the presence is free.
     *
     * A direct port of GlobEmpire in sim/bots.py. If the two disagree, this one
     * is wrong.
     *
     * @param array<string, array> $towns
     * @return array{produce: array<string, int>, moves: array<int, array{from: string, to: string, count: int}>, resolve: ?string, disband: array<string, int>}
     */
    public static function globEmpireTurn(Scenario $scenario, array $towns): array
    {
        // Everything is planned against a copy of the board with the resolution
        // already applied, in the engine's own order: resolve, build, march.
        // Planning the march *before* the build is what implements "overbuild
        // is fine if this turn's moves cover it" — by the time production is
        // chosen the new ceiling is a fact rather than a promise.
        $board = $towns;

        $resolve = self::globResolution($scenario, $board);
        if ($resolve !== null) {
            $board = self::globApplyResolution($board, $resolve);
        }

        // Production is legal against the board as it stands when the troops
        // are built — before the march — but it is *paid for* by the network
        // the march leaves behind.
        $standing = $board;
        $moves = self::globMoves($scenario, $board);
        $produce = self::globProduction($scenario, $standing, $board);
        foreach ($produce as $townId => $count) {
            $board[$townId]['troops'] += $count;
        }

        return [
            'produce' => $produce,
            'moves' => $moves,
            'resolve' => $resolve,
            'disband' => self::globDisband($scenario, $board),
        ];
    }

    /**
     * The most presence a pile could possibly be hiding.
     *
     * Face-up cards count exactly. A look moves a card out of the pile and on
     * to the table, so everything the bot has peeked at is already in
     * `revealed` and is used here without any extra bookkeeping. Whatever is
     * still face down is assumed to be the best card in the deck.
     *
     * This is an upper bound on real presence, which is what makes a
     * resolution against it *certain* rather than merely likely.
     *
     * @param array{pile: array, revealed: array} $town
     */
    public static function globWorstCase(Scenario $scenario, array $town): int
    {
        $known = 0;
        foreach ($town['revealed'] as $card) {
            $known += (int) $card['presence'];
        }
        return $known + count($town['pile']) * $scenario->maxCardPresence();
    }

    /** Troops required to hold a town against anything it could be hiding. */
    private static function globGarrisonNeeded(Scenario $scenario, array $town): int
    {
        if ($town['resolved']) {
            return 0;
        }
        return (int) ceil(self::globWorstCase($scenario, $town) / $scenario->unitPresence());
    }

    /**
     * Take a certain win, richest first; never a gamble.
     *
     * An empty town is a certain win worth nothing, and worth taking anyway
     * when there is nothing better: it locks the town's supply and production
     * to the Empire for the rest of the game.
     *
     * A win here is certain, so the garrison is *not* spent (Decision 3) and
     * may march out in the same turn. That is why this bot does not use the
     * "hold still where you are fighting" filter `empireTurn` does — the filter
     * exists for a bot that cannot know how its fight went, and this one has
     * already checked.
     *
     * @param array<string, array> $towns
     */
    private static function globResolution(Scenario $scenario, array $towns): ?string
    {
        $best = null;
        $bestKey = null;

        foreach ($towns as $townId => $town) {
            if ($town['resolved'] || $town['troops'] <= 0) {
                continue;
            }
            $worst = self::globWorstCase($scenario, $town);
            if (Rules::troopPresence((int) $town['troops'], $scenario->unitPresence()) < $worst) {
                continue;
            }
            // Richest first for the points; then a production town, whose
            // ownership survives the garrison marching away; then stable.
            $key = [$worst, Rules::townProduction($town), $townId];
            if ($bestKey === null || $key > $bestKey) {
                $best = $townId;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * The board as it stands once a resolution the Empire is certain to win has
     * been applied: the town freezes, the cards come off, the garrison stays.
     *
     * @param array<string, array> $towns
     * @return array<string, array>
     */
    private static function globApplyResolution(array $towns, string $townId): array
    {
        $towns[$townId]['resolved'] = true;
        $towns[$townId]['winner'] = Rules::EMPIRE;
        $towns[$townId]['pile'] = [];
        $towns[$townId]['revealed'] = [];
        return $towns;
    }

    /**
     * Reinforce or withdraw what is losing, then spread into what is free.
     *
     * `$board` is mutated as moves are committed, so each decision sees the
     * consequences of the last one. `$origin` and `$departed` track what may
     * still legally leave a town: the engine checks departures against the
     * garrison as it stood at the start of the turn, before any arrivals.
     *
     * @param array<string, array> $board
     * @return array<int, array{from: string, to: string, count: int}>
     */
    private static function globMoves(Scenario $scenario, array &$board): array
    {
        $origin = [];
        foreach ($board as $townId => $town) {
            $origin[$townId] = (int) $town['troops'];
        }
        $departed = [];
        $moves = [];
        $tolerated = self::globOverage($scenario, $board);

        // Bound by reference, not by value: an arrow function would capture
        // `$departed` as it was when the closure was made, which is empty.
        /** Troops in this town that have not already been ordered out. */
        $available = static function (string $townId) use ($origin, &$departed): int {
            return $origin[$townId] - ($departed[$townId] ?? 0);
        };

        /** What can leave without abandoning a town we are winning. */
        $spare = function (string $townId) use ($scenario, &$board, &$origin, &$departed): int {
            $keep = self::globGarrisonNeeded($scenario, $board[$townId]);
            $free = $origin[$townId] - ($departed[$townId] ?? 0);
            return max(0, min($free, (int) $board[$townId]['troops'] - $keep));
        };

        /** Apply a move if the networks it leaves behind can still eat. */
        $commit = function (string $from, string $to, int $count)
            use ($scenario, &$board, &$departed, &$moves, $tolerated): bool {
            if ($count <= 0) {
                return false;
            }
            $board[$from]['troops'] -= $count;
            $board[$to]['troops'] += $count;
            if (self::globOverage($scenario, $board) > $tolerated) {
                $board[$from]['troops'] += $count;
                $board[$to]['troops'] -= $count;
                return false;
            }
            $departed[$from] = ($departed[$from] ?? 0) + $count;
            $moves[] = ['from' => $from, 'to' => $to, 'count' => $count];
            return true;
        };

        self::globRescue($scenario, $board, $available, $spare, $commit);
        self::globExpand($scenario, $board, $spare, $commit);

        return $moves;
    }

    /**
     * Reinforce a garrison that can be saved; withdraw one that cannot.
     *
     * A town is in trouble when the worst its pile could be beats the troops
     * standing in it by RETREAT_MARGIN or more. Being behind by less is left
     * alone: the pile is an upper bound, not a reading, and a single card is as
     * likely to be a bluff as a threat.
     *
     * Reinforcement is all-or-nothing. Sending two troops into a town that
     * needs three loses three troops instead of one, which is the mistake the
     * margin exists to stop.
     *
     * @param array<string, array> $board
     */
    private static function globRescue(
        Scenario $scenario,
        array &$board,
        callable $available,
        callable $spare,
        callable $commit,
    ): void {
        $deficit = fn(array $town): int => self::globWorstCase($scenario, $town)
            - Rules::troopPresence((int) $town['troops'], $scenario->unitPresence());

        $troubled = [];
        foreach ($board as $townId => $town) {
            if (!$town['resolved'] && $town['troops'] > 0
                && $deficit($town) >= self::GLOB_RETREAT_MARGIN) {
                $troubled[$townId] = $deficit($town);
            }
        }
        arsort($troubled);

        foreach (array_keys($troubled) as $townId) {
            $wanted = self::globGarrisonNeeded($scenario, $board[$townId])
                - (int) $board[$townId]['troops'];

            $helpers = [];
            foreach ($board[$townId]['neighbors'] as $neighbor) {
                if ($spare($neighbor) > 0) {
                    $helpers[$neighbor] = $spare($neighbor);
                }
            }
            arsort($helpers);
            $helperIds = array_keys($helpers);

            if (array_sum($helpers) >= $wanted) {
                foreach ($helperIds as $helper) {
                    if ($wanted <= 0) {
                        break;
                    }
                    $sending = min($spare($helper), $wanted);
                    if ($commit($helper, $townId, $sending)) {
                        $wanted -= $sending;
                    }
                }
                if ($wanted <= 0) {
                    continue;
                }
                // The supply check refused part of the relief; fall through and
                // treat the town as unsavable rather than leave it half-fed.
            }

            $retreat = self::globRetreatTo($scenario, $board, $townId);
            if ($retreat !== null) {
                $commit($townId, $retreat, $available($townId));
                continue;
            }

            // Nowhere safe to go: mass here instead and come back at it later
            // as one stack rather than a trickle.
            foreach ($helperIds as $helper) {
                $commit($helper, $townId, $spare($helper));
            }
        }
    }

    /**
     * The best neighbouring town to fall back into, or null to stand fast.
     *
     * Falling back is only worth it if the destination is somewhere the
     * garrison is safe and still fed: a town the rebels have already won
     * supplies nothing, and a town with a taller pile than this one is the same
     * mistake one step sideways.
     *
     * @param array<string, array> $board
     */
    private static function globRetreatTo(Scenario $scenario, array $board, string $townId): ?string
    {
        $arriving = (int) $board[$townId]['troops'];
        $best = null;
        $bestKey = null;

        foreach ($board[$townId]['neighbors'] as $neighborId) {
            $neighbor = $board[$neighborId];
            if ($neighbor['resolved'] && $neighbor['winner'] === Rules::INSURGENCY) {
                continue; // permanently barren ground
            }
            $garrison = Rules::troopPresence(
                (int) $neighbor['troops'] + $arriving,
                $scenario->unitPresence(),
            );
            if ($garrison < self::globWorstCase($scenario, $neighbor)) {
                continue; // losing there too
            }
            $key = [
                $neighbor['resolved'] ? 1 : 0,        // settled ground first
                $neighbor['troops'] > 0 ? 1 : 0,      // then somewhere we stand
                Rules::townSupply($neighbor),
                $neighborId,
            ];
            if ($bestKey === null || $key > $bestKey) {
                $best = $neighborId;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * Spread into every free town this turn's troops can certainly hold.
     *
     * Taken one at a time, re-deriving the options after each, because every
     * move changes the network and therefore what the next one can afford. The
     * preference is for a *seeded* town over an empty one: the supply is
     * identical and the presence is points the Empire will collect when it
     * resolves the town next turn.
     *
     * @param array<string, array> $board
     */
    private static function globExpand(
        Scenario $scenario,
        array &$board,
        callable $spare,
        callable $commit,
    ): void {
        $toward = self::globProductionDistance($board);

        while (true) {
            $candidates = [];
            foreach ($board as $sourceId => $source) {
                if ($spare($sourceId) <= 0) {
                    continue;
                }
                foreach ($source['neighbors'] as $targetId) {
                    $target = $board[$targetId];
                    if ($target['resolved'] || $target['troops'] > 0) {
                        continue;
                    }
                    $worst = self::globWorstCase($scenario, $target);
                    $needed = max(1, (int) ceil($worst / $scenario->unitPresence()));
                    if ($needed > $spare($sourceId)) {
                        continue; // cannot be sure of it, so not worth the troops
                    }
                    $candidates[] = [
                        'key' => [
                            $worst,                                 // points, if any
                            Rules::townSupply($target),             // ceiling
                            Rules::townProduction($target),
                            -($toward[$targetId] ?? 99),            // toward a factory
                            $targetId,
                        ],
                        'move' => [$sourceId, $targetId, $needed],
                    ];
                }
            }

            usort($candidates, static fn(array $a, array $b) => $b['key'] <=> $a['key']);

            // The first move the supply check will accept. A refusal is not the
            // end of expansion: a cheaper town elsewhere may still fit.
            $committed = false;
            foreach ($candidates as $candidate) {
                [$from, $to, $count] = $candidate['move'];
                if ($commit($from, $to, $count)) {
                    $committed = true;
                    break;
                }
            }
            if (!$committed) {
                return;
            }
        }
    }

    /**
     * Build up to the ceiling the board will have once the marching is done.
     *
     * `$standing` is the board at the moment the troops are raised, which is
     * what decides where building is legal at all; `$board` is the board the
     * march leaves behind, which is what has to feed them. Because the march is
     * already planned, an overbuild the network is about to grow into is simply
     * a build that fits — which is the whole of "you may build past the ceiling
     * if you are sure you will reach the supply this turn".
     *
     * @param array<string, array> $standing
     * @param array<string, array> $board
     * @return array<string, int>
     */
    private static function globProduction(Scenario $scenario, array $standing, array $board): array
    {
        $produce = [];
        $spare = [];

        foreach (Rules::productionSites($standing, $scenario->productionCost) as $site) {
            $component = Rules::componentOf($board, $site);
            if (!$component) {
                // A site the garrison has marched out of is its own little
                // network once the new troops appear in it: nothing links to
                // it, so it is fed by its own supply and nothing else.
                $component = [$site];
            }

            $key = implode(',', $component);
            if (!isset($spare[$key])) {
                $spare[$key] = max(
                    0,
                    Rules::ceiling($board, $component, $scenario->supplyPerTroop)
                        - Rules::troopsIn($board, $component),
                );
            }

            $want = min(
                Rules::productionCapacity($standing, $site, $scenario->productionCost),
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
     * Name where attrition losses should fall so they do not cut the line.
     *
     * Left alone, attrition takes from the largest garrison, which is often a
     * junction. A town with more troops than the network is over can give some
     * up without emptying, and an emptied town that nothing else routes through
     * costs only its own supply. Anything not named here is still available to
     * the engine, so this is a preference, not a constraint.
     *
     * @param array<string, array> $board
     * @return array<string, int>
     */
    private static function globDisband(Scenario $scenario, array $board): array
    {
        $disband = [];

        foreach (Rules::components($board) as $component) {
            $over = Rules::troopsIn($board, $component)
                - Rules::ceiling($board, $component, $scenario->supplyPerTroop);
            if ($over <= 0) {
                continue;
            }

            $order = $component;
            usort(
                $order,
                static fn(string $a, string $b) => $board[$b]['troops'] <=> $board[$a]['troops'],
            );

            foreach ($order as $townId) {
                $troops = (int) $board[$townId]['troops'];
                if ($troops > $over || !self::globIsJunction($board, $component, $townId)) {
                    $disband[$townId] = $troops;
                }
            }
        }

        return $disband;
    }

    /**
     * Troops across the whole board that their networks cannot feed.
     *
     * @param array<string, array> $towns
     */
    private static function globOverage(Scenario $scenario, array $towns): int
    {
        $over = 0;
        foreach (Rules::components($towns) as $component) {
            $over += max(
                0,
                Rules::troopsIn($towns, $component)
                    - Rules::ceiling($towns, $component, $scenario->supplyPerTroop),
            );
        }
        return $over;
    }

    /**
     * Whether emptying this town would break its network in two.
     *
     * @param array<string, array> $towns
     * @param string[] $component
     */
    private static function globIsJunction(array $towns, array $component, string $townId): bool
    {
        $rest = array_values(array_diff($component, [$townId]));
        if (count($rest) <= 1) {
            return false;
        }

        $inRest = array_flip($rest);
        $seen = [$rest[0] => true];
        $frontier = [$rest[0]];
        while ($frontier) {
            $current = array_pop($frontier);
            foreach ($towns[$current]['neighbors'] as $neighbor) {
                if (isset($inRest[$neighbor]) && !isset($seen[$neighbor])) {
                    $seen[$neighbor] = true;
                    $frontier[] = $neighbor;
                }
            }
        }

        return count($seen) !== count($rest);
    }

    /**
     * Hops from each town to the nearest factory the Empire does not hold.
     *
     * Used only as a tie-break: when two expansions look equally good, take the
     * one that walks toward a second production town.
     *
     * @param array<string, array> $towns
     * @return array<string, int>
     */
    private static function globProductionDistance(array $towns): array
    {
        $distance = [];
        $frontier = [];
        foreach ($towns as $townId => $town) {
            if (Rules::townProduction($town) > 0 && !Rules::empireHolds($town)) {
                $distance[$townId] = 0;
                $frontier[] = $townId;
            }
        }

        while ($frontier) {
            $current = array_shift($frontier);
            foreach ($towns[$current]['neighbors'] as $neighbor) {
                if (!isset($distance[$neighbor])) {
                    $distance[$neighbor] = $distance[$current] + 1;
                    $frontier[] = $neighbor;
                }
            }
        }

        return $distance;
    }
}
