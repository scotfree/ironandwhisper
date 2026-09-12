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
    ceiling,
    component_of,
    headroom,
    legal_resolutions,
    production_capacity,
    production_sites,
    troops_in,
)


# ---------------------------------------------------------------------------
# What the Empire is allowed to know
# ---------------------------------------------------------------------------

def _hold(moves: list[tuple[str, str, int]],
          resolve: str | None) -> list[tuple[str, str, int]]:
    """Drop any march out of the town being resolved.

    A person resolves in a phase of its own and sees the result before deciding
    anything else, so they may march a garrison out of a town they just won. A
    bot submits its whole turn in one call and cannot know how the fight went —
    and if it loses, those troops are gone before the march, which makes the
    turn illegal. So a bot holds still where it is fighting.
    """
    if resolve is None:
        return moves
    return [move for move in moves if move[0] != resolve]


class EmpireBelief:
    """Estimates hidden influence from Empire-legal information only.

    Every card is either face up beside its town or face down in its pile.
    Total deck composition is public, so the expected influence of any card
    still face down is the ratio across everything unaccounted for.
    """

    def __init__(self, state: GameState):
        self.state = state
        scenario = state.scenario

        known_influence = 0
        known_count = 0
        for town in state.towns.values():
            for card in town.revealed:
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
        known = sum(c.influence for c in town.revealed)
        return known + len(town.pile) * self.unknown_rate


# ---------------------------------------------------------------------------
# Random play — a baseline, and a check that the rules never deadlock
# ---------------------------------------------------------------------------

class RandomInsurgency:
    def __init__(self, rng: random.Random | None = None, resolve_chance: float = 0.15):
        self.rng = rng or random.Random()
        self.resolve_chance = resolve_chance

    def choose(self, state: GameState) -> InsurgencyTurn:
        # Resolution comes first in the turn, so it is judged on the board as
        # the Empire left it, and a town resolved now cannot then be placed in.
        resolve = None
        options = legal_resolutions(state, Side.INSURGENCY)
        if options and self.rng.random() < self.resolve_chance:
            resolve = self.rng.choice(options)

        open_towns = [t.id for t in state.unresolved if t.id != resolve]
        placements: dict[str, list[int]] = {}
        if open_towns:
            for index in range(len(state.hand)):
                town_id = self.rng.choice(open_towns)
                placements.setdefault(town_id, []).append(index)

        return InsurgencyTurn(placements=placements, resolve=resolve)


class RandomEmpire:
    def __init__(self, rng: random.Random | None = None,
                 move_chance: float = 0.5, resolve_chance: float = 0.15):
        self.rng = rng or random.Random()
        self.move_chance = move_chance
        self.resolve_chance = resolve_chance

    def choose(self, state: GameState) -> EmpireTurn:
        # Building past the ceiling is legal; these bots decline to, because
        # nothing in them plans a march out to the supply that would feed the
        # overshoot. That is a policy, not a rule.
        produce: dict[str, int] = {}
        spare: dict[frozenset[str], int] = {}
        for site in production_sites(state):
            component = component_of(state, site)
            if not component:
                # Nobody is standing here, so there is no network to overload —
                # and building is the only way back onto the board at all. The
                # troop brings the town's own supply with it.
                produce[site] = production_capacity(state, site)
                continue
            network = frozenset(component)
            if network not in spare:
                spare[network] = headroom(state, site)
            want = min(production_capacity(state, site), spare[network])
            if want > 0:
                produce[site] = want
                spare[network] -= want

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

        # Judged on the board as it stands, not on what this turn is about to
        # do: resolution happens first (Decision 4), so troops built or marched
        # in this turn are not there yet.
        options = legal_resolutions(state, Side.EMPIRE)
        resolve = None
        if options and self.rng.random() < self.resolve_chance:
            resolve = self.rng.choice(options)

        return EmpireTurn(produce=produce, moves=_hold(moves, resolve), resolve=resolve)


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

        # Resolution happens first in the turn (Decision 4), so it is decided
        # against the board as it stands, not against what we are about to do.
        resolve = self._resolution(state, open_towns, strength_of)
        if resolve is not None:
            open_towns = [t for t in open_towns if t.id != resolve]
        if not open_towns:
            return InsurgencyTurn(placements={}, resolve=resolve)

        # Cards are graded, so commit by value rather than by count: spending
        # three ones where a three would do wastes two cards. Biggest first
        # reaches a threshold with the fewest cards, leaving more for elsewhere.
        influence_idx = sorted(
            (i for i, c in enumerate(state.hand) if c.influence > 0),
            key=lambda i: state.hand[i].influence,
            reverse=True,
        )
        worthless_idx = [i for i, c in enumerate(state.hand) if c.influence == 0]

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
            chosen: list[int] = []
            committed = 0
            while influence_idx and committed < needed:
                index = influence_idx.pop(0)
                chosen.append(index)
                committed += state.hand[index].influence
            if chosen:
                placements.setdefault(town.id, []).extend(chosen)

        # Leftover influence goes wherever the Empire is likely to arrive.
        if influence_idx:
            fallback = targets[0] if targets else self.rng.choice(open_towns)
            placements.setdefault(fallback.id, []).extend(influence_idx)

        # Worthless cards: next to Empire troops if baiting, otherwise scattered.
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

        for index in worthless_idx:
            town = self.rng.choice(bait_towns)
            placements.setdefault(town.id, []).append(index)

        return InsurgencyTurn(placements=placements, resolve=resolve)

    def _resolution(self, state, open_towns, strength_of) -> str | None:
        """Cash a town we have already beaten, if the garrison is worth taking."""
        resolve = None
        best_value = self.min_score - 1
        for town in open_towns:
            if town.card_count == 0:
                continue
            influence = state.influence_in(town.id)
            strength = strength_of(town.id)
            if influence > strength and strength > best_value:
                best_value, resolve = strength, town.id
        return resolve


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

        # Resolution happens first in the turn (Decision 4), so it is judged on
        # the troops standing now — there is no marching in and cashing out.
        resolve = None
        best_value = -1.0
        for town in open_towns:
            if town.troops <= 0:
                continue
            estimate = belief.estimated_influence(town.id)
            strength = town.troops * state.scenario.unit.strength
            if strength < estimate * self.confidence:
                continue
            if not self.shrink and estimate < self.min_score:
                continue
            value = estimate if not self.shrink else estimate + 1.0
            if value > best_value:
                best_value, resolve = value, town.id

        def attractiveness(town) -> float:
            return belief.estimated_influence(town.id)

        # Build wherever we can afford to. Production is per town and the
        # ceiling is per network, so this takes what each site offers until the
        # supply runs out — there is rarely a reason to leave capacity idle.
        produce: dict[str, int] = {}
        spare: dict[frozenset[str], int] = {}
        for site in production_sites(state):
            component = component_of(state, site)
            if not component:
                # Nobody is standing here, so there is no network to overload —
                # and building is the only way back onto the board at all. The
                # troop brings the town's own supply with it.
                produce[site] = production_capacity(state, site)
                continue
            network = frozenset(component)
            if network not in spare:
                spare[network] = headroom(state, site)
            want = min(production_capacity(state, site), spare[network])
            if want > 0:
                produce[site] = want
                spare[network] -= want

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

        return EmpireTurn(produce=produce, moves=_hold(moves, resolve), resolve=resolve)


BOTS = {
    "random": (RandomEmpire, RandomInsurgency),
    "heuristic": (HeuristicEmpire, HeuristicInsurgency),
}
