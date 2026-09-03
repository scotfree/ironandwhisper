"""Bot policies for both sides.

A bot is anything with `choose(state) -> InsurgencyTurn | EmpireTurn`.

Important: the Empire bots must only use information the Empire is actually
entitled to. `EmpireBelief` below is the single place that reads hidden state,
and it deliberately reads only what a real Empire player can see — pile
heights, face-up resolved piles, cards it has peeked at, and the publicly
known deck composition.
"""

from __future__ import annotations

import random

from .engine import (
    EmpireTurn,
    GameState,
    InsurgencyTurn,
    Side,
    legal_generation_towns,
    legal_resolutions,
)


# ---------------------------------------------------------------------------
# What the Empire is allowed to know
# ---------------------------------------------------------------------------

def project_troops(state: GameState, generate_at: str | None,
                   moves: list[tuple[str, str, int]]) -> dict[str, int]:
    """Troop counts as they will stand after generation and movement.

    An Empire turn generates, then moves, then resolves, so any decision about
    resolving has to be made against the projected board rather than the
    current one.
    """
    projected = {tid: town.troops for tid, town in state.towns.items()}
    if generate_at is not None:
        projected[generate_at] += state.scenario.generation_rate
    for src, dst, quantity in moves:
        projected[src] -= quantity
        projected[dst] += quantity
    return projected


class EmpireBelief:
    """Estimates hidden influence from Empire-legal information only.

    Every card is either known (peeked at, or face up in a resolved town) or
    unknown. Total deck composition is public, so the expected influence of any
    unknown card is just the ratio across all cards still unaccounted for.
    """

    def __init__(self, state: GameState):
        self.state = state
        scenario = state.scenario

        known_influence = 0
        known_count = 0
        for town in state.towns.values():
            for card in town.pile:
                if card.uid in state.empire_known_uids:
                    known_influence += card.influence
                    known_count += 1

        total_cards = scenario.deck_size
        total_influence = scenario.total_influence

        unknown_count = total_cards - known_count
        unknown_influence = total_influence - known_influence
        self.unknown_rate = (
            unknown_influence / unknown_count if unknown_count > 0 else 0.0
        )

    def estimated_influence(self, town_id: str) -> float:
        """Best guess at the real influence sitting in an unresolved pile."""
        town = self.state.towns[town_id]
        estimate = 0.0
        for card in town.pile:
            if card.uid in self.state.empire_known_uids:
                estimate += card.influence
            else:
                estimate += self.unknown_rate
        return estimate


# ---------------------------------------------------------------------------
# Random play — a baseline, and a check that the rules never deadlock
# ---------------------------------------------------------------------------

class RandomInsurgency:
    def __init__(self, rng: random.Random | None = None, resolve_chance: float = 0.15):
        self.rng = rng or random.Random()
        self.resolve_chance = resolve_chance

    def choose(self, state: GameState) -> InsurgencyTurn:
        open_towns = [t.id for t in state.unresolved]
        placements: dict[str, list[int]] = {}
        for index in range(len(state.hand)):
            town_id = self.rng.choice(open_towns)
            placements.setdefault(town_id, []).append(index)

        resolve = None
        options = legal_resolutions(state, Side.INSURGENCY)
        # Placements land before the resolution check, so a town we just seeded
        # is a legal target even if it was empty a moment ago.
        seeded = [t for t in placements if t not in options]
        options = options + seeded
        if options and self.rng.random() < self.resolve_chance:
            resolve = self.rng.choice(options)

        return InsurgencyTurn(placements=placements, resolve=resolve)


class RandomEmpire:
    def __init__(self, rng: random.Random | None = None,
                 move_chance: float = 0.5, resolve_chance: float = 0.15):
        self.rng = rng or random.Random()
        self.move_chance = move_chance
        self.resolve_chance = resolve_chance

    def choose(self, state: GameState) -> EmpireTurn:
        anchors = legal_generation_towns(state)
        generate_at = self.rng.choice(anchors) if anchors else None

        moves = []
        for town in state.towns.values():
            # Resolved towns are included: troops raised there after the fact
            # are free to march out, and stranding them is a bot bug, not a rule.
            if town.troops == 0:
                continue
            open_neighbors = list(town.neighbors)
            if open_neighbors and self.rng.random() < self.move_chance:
                quantity = self.rng.randint(1, town.troops)
                moves.append((town.id, self.rng.choice(open_neighbors), quantity))

        # Judge against the projected board: the engine moves before resolving.
        projected = project_troops(state, generate_at, moves)
        options = [
            t.id for t in state.unresolved if projected[t.id] > 0
        ]
        resolve = None
        if options and self.rng.random() < self.resolve_chance:
            resolve = self.rng.choice(options)

        return EmpireTurn(generate_at=generate_at, moves=moves, resolve=resolve)


# ---------------------------------------------------------------------------
# Heuristic play
# ---------------------------------------------------------------------------

class HeuristicInsurgency:
    """Concentrates influence where the Empire has committed, dumps dummies as noise.

    Parameters exist so the notebook can ask whether concentrating beats
    spreading, rather than assuming an answer.

    min_score   don't bother resolving for fewer points than this
    margin      how much to overshoot the Empire's strength by when committing
    spread      how many towns to divide influence across each turn
    bait        place dummies next to Empire troops to invite over-commitment
    """

    def __init__(self, rng: random.Random | None = None, min_score: int = 3,
                 margin: int = 1, spread: int = 1, bait: bool = True):
        self.rng = rng or random.Random()
        self.min_score = min_score
        self.margin = margin
        self.spread = spread
        self.bait = bait

    def choose(self, state: GameState) -> InsurgencyTurn:
        strength_of = state.strength_in
        open_towns = [t for t in state.unresolved]

        influence_idx = [i for i, c in enumerate(state.hand) if c.influence > 0]
        dummy_idx = [i for i, c in enumerate(state.hand) if c.influence == 0]

        placements: dict[str, list[int]] = {}

        # Targets: garrisoned towns we could plausibly flip, richest first.
        targets = sorted(
            (t for t in open_towns if t.troops > 0),
            key=lambda t: strength_of(t.id),
            reverse=True,
        )[: max(1, self.spread)]

        for town in targets:
            if not influence_idx:
                break
            needed = strength_of(town.id) - state.influence_in(town.id) + self.margin
            take = min(len(influence_idx), max(0, needed))
            if take:
                chosen, influence_idx = influence_idx[:take], influence_idx[take:]
                placements.setdefault(town.id, []).extend(chosen)

        # Leftover influence goes wherever the Empire is likely to arrive.
        if influence_idx:
            fallback = targets[0] if targets else self.rng.choice(open_towns)
            placements.setdefault(fallback.id, []).extend(influence_idx)

        # Dummies: next to Empire troops if baiting, otherwise scattered.
        if self.bait:
            bait_towns = [
                t for t in open_towns
                if t.troops > 0 or any(
                    state.towns[n].troops > 0 for n in t.neighbors
                    if not state.towns[n].resolved
                )
            ] or open_towns
        else:
            bait_towns = open_towns

        for index in dummy_idx:
            town = self.rng.choice(bait_towns)
            placements.setdefault(town.id, []).append(index)

        # Resolve where we now win and the prize is worth taking.
        resolve = None
        best_value = self.min_score - 1
        for town in open_towns:
            influence = state.influence_in(town.id) + sum(
                state.hand[i].influence for i in placements.get(town.id, [])
            )
            strength = strength_of(town.id)
            if influence > strength and strength > best_value:
                best_value, resolve = strength, town.id

        return InsurgencyTurn(placements=placements, resolve=resolve)


class HeuristicEmpire:
    """Marches toward tall piles and resolves when it believes it wins.

    min_score      don't resolve for fewer points than this, unless shrinking
    confidence     required ratio of strength to estimated influence
    shrink         resolve any town we can win, even for zero points, to freeze
                   the board and gain a permanent generation anchor
    """

    def __init__(self, rng: random.Random | None = None, min_score: float = 2.0,
                 confidence: float = 1.15, shrink: bool = False):
        self.rng = rng or random.Random()
        self.min_score = min_score
        self.confidence = confidence
        self.shrink = shrink

    def choose(self, state: GameState) -> EmpireTurn:
        belief = EmpireBelief(state)
        open_towns = [t for t in state.unresolved]

        def attractiveness(town) -> float:
            return belief.estimated_influence(town.id)

        # Generate where the fighting is: the anchor nearest a fat pile.
        anchors = legal_generation_towns(state)
        generate_at = None
        if anchors:
            hot = max(open_towns, key=attractiveness, default=None)
            if hot is not None:
                distances = state.scenario.map.distances_from(hot.id)
                generate_at = min(anchors, key=lambda tid: distances.get(tid, 99))
            else:
                generate_at = self.rng.choice(anchors)

        # March toward the most attractive reachable unresolved town, but leave
        # a garrison anywhere we already look like we are winning.
        moves = []
        for town in state.towns.values():
            if town.troops == 0:
                continue
            neighbors = list(town.neighbors)
            if not neighbors:
                continue
            best = max(neighbors, key=lambda n: belief.estimated_influence(n))

            if town.resolved:
                # Nothing left to win here. March toward whatever is still live.
                moves.append((town.id, best, town.troops))
                continue

            estimate = belief.estimated_influence(town.id)
            strength = town.troops * state.scenario.unit.strength
            if estimate > 0 and strength >= estimate * self.confidence:
                continue  # hold: we think we win here already
            if belief.estimated_influence(best) > estimate:
                moves.append((town.id, best, town.troops))

        # Resolve where we believe we win by enough. The engine generates and
        # moves before resolving, so judge against where the troops will BE,
        # not where they are now.
        projected = project_troops(state, generate_at, moves)
        resolve = None
        best_value = -1.0
        for town in open_towns:
            if projected[town.id] <= 0:
                continue
            estimate = belief.estimated_influence(town.id)
            strength = projected[town.id] * state.scenario.unit.strength
            if strength < estimate * self.confidence:
                continue
            if not self.shrink and estimate < self.min_score:
                continue
            value = estimate if not self.shrink else estimate + 1.0
            if value > best_value:
                best_value, resolve = value, town.id

        return EmpireTurn(generate_at=generate_at, moves=moves, resolve=resolve)


BOTS = {
    "random": (RandomEmpire, RandomInsurgency),
    "heuristic": (HeuristicEmpire, HeuristicInsurgency),
}
