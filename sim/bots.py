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
    empire_components,
    empire_holds,
    headroom,
    legal_resolutions,
    production_capacity,
    production_sites,
    resolve_town,
    town_production,
    town_supply,
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


class GlobEmpire:
    """Plays for the supply network, and only fights battles it has already won.

    The heuristic Empire above marches at the tallest pile it can find and
    resolves on a ratio it believes in. This one does neither. It plays the way
    the game has actually been played well at a table:

    * resolve only a *certain* win, judged against the worst the face-down
      cards could possibly be;
    * then spread outward in a wave, because the ceiling is what keeps an army
      alive and a three-card hand cannot contest three new towns at once;
    * and withdraw a garrison that has fallen behind rather than feed it in.

    The objective is *ceiling*, not points. Points arrive as a by-product of
    resolving towns it was already safe in — which is why an expansion prefers
    a seeded town it can beat over an empty one: the supply is the same and the
    influence is free.

    retreat_margin  how far behind a garrison may fall before it withdraws

    The margin is large on purpose. `worst_case` assumes every face-down card
    is the best in the deck, and at baseline the deck is 40% bluffs, so a pile
    that *could* beat a garrison by two usually does not. A sweep over 500 games
    per setting puts the Empire at 44% at a margin of 3, 64% at 5, 66% at 6 and
    back to 46% at 14 — a broad plateau rather than the cliffs the parameter
    sweeps usually find. Retreating too eagerly hands towns to bluffs; never
    retreating feeds garrisons into piles that really were tall.
    """

    def __init__(self, rng: random.Random | None = None, retreat_margin: int = 5):
        self.rng = rng or random.Random()
        self.retreat_margin = retreat_margin

    # -- what the Empire is entitled to know --------------------------------

    @staticmethod
    def worst_case(state: GameState, town) -> int:
        """The most influence this pile could possibly be hiding.

        Face-up cards count exactly. A look moves a card out of the pile and on
        to the table, so everything this bot has peeked at is already in
        `revealed` and is used here without any extra bookkeeping. Whatever is
        still face down is assumed to be the best card in the deck.

        This is an upper bound on real influence, which is what makes a
        resolution against it *certain* rather than merely likely.
        """
        cap = state.scenario.max_card_influence
        return sum(c.influence for c in town.revealed) + len(town.pile) * cap

    def _garrison_needed(self, state: GameState, town) -> int:
        """Troops required to hold this town against anything it could hide."""
        if town.resolved:
            return 0
        strength = state.scenario.unit.strength
        return -(-self.worst_case(state, town) // strength)  # ceiling division

    # -- the turn ------------------------------------------------------------

    def choose(self, state: GameState) -> EmpireTurn:
        # Everything is planned against a copy of the board with the resolution
        # already applied, in the engine's own order: resolve, build, march.
        # Planning the march *before* the build is what implements "overbuild is
        # fine if this turn's moves cover it" — by the time production is chosen
        # the new ceiling is a fact rather than a promise.
        board = state.clone()

        resolve = self._resolution(board)
        if resolve is not None:
            resolve_town(board, resolve, Side.EMPIRE)

        # Production is legal against the board as it stands when the troops are
        # built — before the march — but it is *paid for* by the network the
        # march leaves behind. So the sites come from `standing` and the ceiling
        # from `board`, which is the same clone once the moves are applied.
        standing = board.clone()
        moves = self._moves(board)
        produce = self._production(standing, board)
        for town_id, count in produce.items():
            board.towns[town_id].troops += count

        return EmpireTurn(
            produce=produce,
            moves=moves,
            resolve=resolve,
            disband=self._disband(board),
        )

    # -- phase 1: resolve ----------------------------------------------------

    def _resolution(self, board: GameState) -> str | None:
        """Take a certain win, richest first; never a gamble.

        An empty town is a certain win worth nothing, and worth taking anyway
        when there is nothing better: it locks the town's supply and production
        to the Empire for the rest of the game.

        A win here is certain, so the garrison is *not* spent (Decision 3) and
        may march out in the same turn. That is why this bot does not use the
        `_hold` guard the others do — the guard exists for a bot that cannot
        know how its fight went, and this one has already checked.
        """
        best, best_key = None, None
        for town in board.unresolved:
            if town.troops <= 0:
                continue
            if town.troops * board.scenario.unit.strength < self.worst_case(board, town):
                continue
            # Richest first for the points; then a production town, whose
            # ownership survives the garrison marching away; then stable.
            key = (self.worst_case(board, town), town_production(board, town.id), town.id)
            if best_key is None or key > best_key:
                best, best_key = town.id, key
        return best

    # -- phase 2: march ------------------------------------------------------

    def _moves(self, board: GameState) -> list[tuple[str, str, int]]:
        """Reinforce or withdraw what is losing, then spread into what is free.

        The board is mutated as moves are committed, so each decision sees the
        consequences of the last one. `origin` and `departed` track what may
        still legally leave a town: the engine checks departures against the
        garrison as it stood at the start of the turn, before any arrivals.
        """
        origin = {tid: t.troops for tid, t in board.towns.items()}
        departed: dict[str, int] = {}
        moves: list[tuple[str, str, int]] = []
        tolerated = _overage(board)

        def available(town_id: str) -> int:
            """Troops in this town that have not already been ordered out."""
            return origin[town_id] - departed.get(town_id, 0)

        def spare(town_id: str) -> int:
            """What can leave without abandoning a town we are winning."""
            town = board.towns[town_id]
            keep = self._garrison_needed(board, town)
            return max(0, min(available(town_id), town.troops - keep))

        def commit(src: str, dst: str, quantity: int) -> bool:
            """Apply a move if the networks it leaves behind can still eat."""
            board.towns[src].troops -= quantity
            board.towns[dst].troops += quantity
            if _overage(board) > tolerated:
                board.towns[src].troops += quantity
                board.towns[dst].troops -= quantity
                return False
            departed[src] = departed.get(src, 0) + quantity
            moves.append((src, dst, quantity))
            return True

        self._rescue(board, available, spare, commit)
        self._expand(board, commit, spare)
        return moves

    def _rescue(self, board, available, spare, commit) -> None:
        """Reinforce a garrison that can be saved; withdraw one that cannot.

        A town is in trouble when the worst its pile could be beats the troops
        standing in it by `retreat_margin` or more. Being behind by one is left
        alone: the pile is an upper bound, not a reading, and a single card is
        as likely to be a bluff as a threat.

        Reinforcement is all-or-nothing. Sending two troops into a town that
        needs three loses three troops instead of one, which is the mistake the
        margin exists to stop.
        """
        strength = board.scenario.unit.strength

        def deficit(town) -> int:
            return self.worst_case(board, town) - town.troops * strength

        troubled = sorted(
            (t for t in board.unresolved if t.troops > 0 and deficit(t) >= self.retreat_margin),
            key=deficit,
            reverse=True,
        )

        for town in troubled:
            wanted = self._garrison_needed(board, town) - town.troops
            helpers = sorted(
                (n for n in town.neighbors if spare(n) > 0),
                key=spare,
                reverse=True,
            )
            if sum(spare(n) for n in helpers) >= wanted:
                for helper in helpers:
                    if wanted <= 0:
                        break
                    sending = min(spare(helper), wanted)
                    if commit(helper, town.id, sending):
                        wanted -= sending
                if wanted <= 0:
                    continue
                # The supply check refused part of the relief; fall through and
                # treat the town as unsavable rather than leave it half-fed.

            retreat = self._retreat_to(board, town)
            if retreat is not None:
                commit(town.id, retreat, available(town.id))
            else:
                # Nowhere safe to go: mass here instead and come back at it
                # later as one stack rather than a trickle.
                for helper in helpers:
                    commit(helper, town.id, spare(helper))

    def _retreat_to(self, board: GameState, town) -> str | None:
        """The best neighbouring town to fall back into, or None to stand fast.

        Falling back is only worth it if the destination is somewhere the
        garrison is safe and still fed: a town the rebels have already won
        supplies nothing, and a town with a taller pile than this one is the
        same mistake one step sideways.
        """
        strength = board.scenario.unit.strength
        arriving = town.troops
        best, best_key = None, None
        for neighbor_id in town.neighbors:
            neighbor = board.towns[neighbor_id]
            if neighbor.resolved and neighbor.winner is Side.INSURGENCY:
                continue  # permanently barren ground
            garrison = (neighbor.troops + arriving) * strength
            if garrison < self.worst_case(board, neighbor):
                continue  # losing there too
            key = (
                neighbor.resolved,                      # settled ground first
                neighbor.troops > 0,                    # then somewhere we stand
                town_supply(board, neighbor_id),
                neighbor_id,
            )
            if best_key is None or key > best_key:
                best, best_key = neighbor_id, key
        return best

    def _expand(self, board: GameState, commit, spare) -> None:
        """Spread into every free town this turn's troops can certainly hold.

        Taken one at a time, re-deriving the options after each, because every
        move changes the network and therefore what the next one can afford.
        The preference is for a *seeded* town over an empty one: the supply is
        identical and the influence is points the Empire will collect when it
        resolves the town next turn.
        """
        toward = _production_distance(board)
        strength = board.scenario.unit.strength

        while True:
            candidates = []
            for source in board.towns.values():
                if spare(source.id) <= 0:
                    continue
                for target_id in source.neighbors:
                    target = board.towns[target_id]
                    if target.resolved or target.troops > 0:
                        continue
                    needed = max(1, -(-self.worst_case(board, target) // strength))
                    if needed > spare(source.id):
                        continue  # cannot be sure of it, so not worth the troops
                    key = (
                        self.worst_case(board, target),      # points, if any
                        town_supply(board, target_id),       # ceiling
                        town_production(board, target_id),
                        -toward.get(target_id, 99),          # toward a factory
                        target_id,
                    )
                    candidates.append((key, (source.id, target_id, needed)))

            candidates.sort(key=lambda pair: pair[0], reverse=True)
            # The first move the supply check will accept. A refusal is not the
            # end of expansion: a cheaper town somewhere else may still fit.
            if not any(commit(*move) for _, move in candidates):
                return

    # -- phase 3: build ------------------------------------------------------

    def _production(self, standing: GameState, board: GameState) -> dict[str, int]:
        """Build up to the ceiling the board will have once the marching is done.

        `standing` is the board at the moment the troops are raised, which is
        what decides where building is legal at all; `board` is the board the
        march leaves behind, which is what has to feed them. Because the march
        is already planned, an overbuild the network is about to grow into is
        simply a build that fits — which is the whole of "you may build past the
        ceiling if you are sure you will reach the supply this turn".
        """
        produce: dict[str, int] = {}
        spare: dict[frozenset[str], int] = {}
        for site in production_sites(standing):
            component = component_of(board, site) or {site}
            network = frozenset(component)
            if network not in spare:
                # A site the garrison has marched out of is its own little
                # network once the new troops appear in it: nothing links to it,
                # so it is fed by its own supply and nothing else.
                spare[network] = max(
                    0, ceiling(board, component) - troops_in(board, component)
                )
            want = min(production_capacity(standing, site), spare[network])
            if want > 0:
                produce[site] = want
                spare[network] -= want
        return produce

    # -- phase 4: attrition --------------------------------------------------

    def _disband(self, board: GameState) -> dict[str, int]:
        """Name where losses should fall so they do not cut the line.

        Left alone, attrition takes from the largest garrison, which is often a
        junction. A town with more troops than the network is over can give some
        up without emptying, and an emptied town that nothing else routes
        through costs only its own supply. Anything not named here is still
        available to the engine, so this is a preference, not a constraint.
        """
        disband: dict[str, int] = {}
        for component in empire_components(board):
            over = troops_in(board, component) - ceiling(board, component)
            if over <= 0:
                continue
            for town_id in sorted(component, key=lambda t: -board.towns[t].troops):
                troops = board.towns[town_id].troops
                if troops > over or not _is_junction(board, component, town_id):
                    disband[town_id] = troops
        return disband


def _overage(state: GameState) -> int:
    """Troops across the whole board that their networks cannot feed."""
    return sum(
        max(0, troops_in(state, component) - ceiling(state, component))
        for component in empire_components(state)
    )


def _is_junction(state: GameState, component: set[str], town_id: str) -> bool:
    """Whether emptying this town would break its network in two."""
    rest = component - {town_id}
    if len(rest) <= 1:
        return False
    start = next(iter(rest))
    seen = {start}
    frontier = [start]
    while frontier:
        current = frontier.pop()
        for neighbor in state.towns[current].neighbors:
            if neighbor in rest and neighbor not in seen:
                seen.add(neighbor)
                frontier.append(neighbor)
    return seen != rest


def _production_distance(state: GameState) -> dict[str, int]:
    """Hops from each town to the nearest factory the Empire does not hold.

    Used only as a tie-break: when two expansions look equally good, take the
    one that walks toward a second production town.
    """
    targets = [
        t.id for t in state.towns.values()
        if town_production(state, t.id) > 0 and not empire_holds(state, t.id)
    ]
    distance = {tid: 0 for tid in targets}
    frontier = list(targets)
    while frontier:
        current = frontier.pop(0)
        for neighbor in state.towns[current].neighbors:
            if neighbor not in distance:
                distance[neighbor] = distance[current] + 1
                frontier.append(neighbor)
    return distance


BOTS = {
    "random": (RandomEmpire, RandomInsurgency),
    "heuristic": (HeuristicEmpire, HeuristicInsurgency),
    "glob": (GlobEmpire, HeuristicInsurgency),
}

EMPIRE_BOTS = {
    "random": RandomEmpire,
    "heuristic": HeuristicEmpire,
    "glob": GlobEmpire,
}

INSURGENCY_BOTS = {
    "random": RandomInsurgency,
    "heuristic": HeuristicInsurgency,
}
