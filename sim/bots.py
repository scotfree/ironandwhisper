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
    """Estimates hidden presence from Empire-legal information only.

    Every card is either face up beside its town or face down in its pile.
    Total deck composition is public, so the expected presence of any card
    still face down is the ratio across everything unaccounted for.
    """

    def __init__(self, state: GameState):
        self.state = state
        scenario = state.scenario

        known_presence = 0
        known_count = 0
        for town in state.towns.values():
            for card in town.revealed:
                known_presence += card.presence
                known_count += 1

        total_cards = scenario.deck_size
        total_card_presence = scenario.total_card_presence

        unknown_count = total_cards - known_count
        unknown_presence = total_card_presence - known_presence
        self.unknown_rate = (
            unknown_presence / unknown_count if unknown_count > 0 else 0.0
        )

    def estimated_presence(self, town_id: str) -> float:
        """Best guess at the real presence sitting in an unresolved pile."""
        town = self.state.towns[town_id]
        known = sum(c.presence for c in town.revealed)
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
    """Concentrates presence where the Empire has committed, dumps dummies as noise.

    Parameters exist so the notebook can ask whether concentrating beats
    spreading, rather than assuming an answer.

    min_score   don't bother resolving for fewer points than this
    margin      how much to overshoot the Empire's presence by when committing
    spread      how many towns to divide presence across each turn
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
        troop_presence_of = state.troop_presence_in
        open_towns = [t for t in state.unresolved]

        # Resolution happens first in the turn (Decision 4), so it is decided
        # against the board as it stands, not against what we are about to do.
        resolve = self._resolution(state, open_towns, troop_presence_of)
        if resolve is not None:
            open_towns = [t for t in open_towns if t.id != resolve]
        if not open_towns:
            return InsurgencyTurn(placements={}, resolve=resolve)

        # Cards are graded, so commit by value rather than by count: spending
        # three ones where a three would do wastes two cards. Biggest first
        # reaches a threshold with the fewest cards, leaving more for elsewhere.
        presence_idx = sorted(
            (i for i, c in enumerate(state.hand) if c.presence > 0),
            key=lambda i: state.hand[i].presence,
            reverse=True,
        )
        worthless_idx = [i for i, c in enumerate(state.hand) if c.presence == 0]

        placements: dict[str, list[int]] = {}

        # Targets: garrisoned towns we could plausibly flip, richest first.
        targets = sorted(
            (t for t in open_towns if t.troops > 0),
            key=lambda t: troop_presence_of(t.id),
            reverse=True,
        )[: max(1, self.spread)]

        for town in targets:
            if not presence_idx:
                break
            needed = troop_presence_of(town.id) - state.card_presence_in(town.id) + self.margin
            chosen: list[int] = []
            committed = 0
            while presence_idx and committed < needed:
                index = presence_idx.pop(0)
                chosen.append(index)
                committed += state.hand[index].presence
            if chosen:
                placements.setdefault(town.id, []).extend(chosen)

        # Leftover presence goes wherever the Empire is likely to arrive.
        if presence_idx:
            fallback = targets[0] if targets else self.rng.choice(open_towns)
            placements.setdefault(fallback.id, []).extend(presence_idx)

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

    def _resolution(self, state, open_towns, troop_presence_of) -> str | None:
        """Cash a town we have already beaten, if the garrison is worth taking."""
        resolve = None
        best_value = self.min_score - 1
        for town in open_towns:
            if town.card_count == 0:
                continue
            card_presence = state.card_presence_in(town.id)
            troop_presence = troop_presence_of(town.id)
            if card_presence > troop_presence and troop_presence > best_value:
                best_value, resolve = troop_presence, town.id
        return resolve


class HeuristicEmpire:
    """Marches toward tall piles and resolves when it believes it wins.

    min_score      don't resolve for fewer points than this, unless shrinking
    confidence     required ratio of troop presence to estimated card presence
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
            estimate = belief.estimated_presence(town.id)
            troop_presence = town.troops * state.scenario.unit.presence
            if troop_presence < estimate * self.confidence:
                continue
            if not self.shrink and estimate < self.min_score:
                continue
            value = estimate if not self.shrink else estimate + 1.0
            if value > best_value:
                best_value, resolve = value, town.id

        def attractiveness(town) -> float:
            return belief.estimated_presence(town.id)

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
            best = max(neighbors, key=lambda n: belief.estimated_presence(n))

            if town.resolved:
                # Nothing left to win here. March toward whatever is still live.
                moves.append((town.id, best, town.troops))
                continue

            estimate = belief.estimated_presence(town.id)
            troop_presence = town.troops * state.scenario.unit.presence
            if estimate > 0 and troop_presence >= estimate * self.confidence:
                continue  # hold: we think we win here already
            if belief.estimated_presence(best) > estimate:
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
    presence is free.

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
        """The most presence this pile could possibly be hiding.

        Face-up cards count exactly. A look moves a card out of the pile and on
        to the table, so everything this bot has peeked at is already in
        `revealed` and is used here without any extra bookkeeping. Whatever is
        still face down is assumed to be the best card in the deck.

        This is an upper bound on real presence, which is what makes a
        resolution against it *certain* rather than merely likely.
        """
        cap = state.scenario.max_card_presence
        return sum(c.presence for c in town.revealed) + len(town.pile) * cap

    def _garrison_needed(self, state: GameState, town) -> int:
        """Troops required to hold this town against anything it could hide."""
        if town.resolved:
            return 0
        troop_presence = state.scenario.unit.presence
        return -(-self.worst_case(state, town) // troop_presence)  # ceiling division

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
            if town.troops * board.scenario.unit.presence < self.worst_case(board, town):
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
        troop_presence = board.scenario.unit.presence

        def deficit(town) -> int:
            return self.worst_case(board, town) - town.troops * troop_presence

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
        troop_presence = board.scenario.unit.presence
        arriving = town.troops
        best, best_key = None, None
        for neighbor_id in town.neighbors:
            neighbor = board.towns[neighbor_id]
            if neighbor.resolved and neighbor.winner is Side.INSURGENCY:
                continue  # permanently barren ground
            garrison = (neighbor.troops + arriving) * troop_presence
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
        identical and the presence is points the Empire will collect when it
        resolves the town next turn.
        """
        toward = _production_distance(board)
        troop_presence = board.scenario.unit.presence

        while True:
            candidates = []
            for source in board.towns.values():
                if spare(source.id) <= 0:
                    continue
                for target_id in source.neighbors:
                    target = board.towns[target_id]
                    if target.resolved or target.troops > 0:
                        continue
                    needed = max(1, -(-self.worst_case(board, target) // troop_presence))
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


class Glob2Empire(GlobEmpire):
    """GlobEmpire, tightened by three games of real play against MistBot.

    GlobEmpire's retreat margin is tuned not to flinch at a bluff — right for
    a garrison the main army can reach and reinforce in one motion, wrong for
    a garrison standing alone with nobody next door to send. MistBot's
    cheapest attack is "clear the garrison by exactly one", and a margin of 5
    will not react to that until the town is already lost — worse, a lone
    garrison usually *cannot* be rescued at all, since rescue needs a
    neighbour with spare troops and an outpost by definition has none. Two
    human games where the Empire held everything in one blob scored 5-0 and
    6-0 against MistBot; the one where it scattered into five separate
    garrisons lost 7-2, one captured garrison at a time (issue #18).

    The fix is not a lower margin everywhere — GlobEmpire's own sweep found a
    broad plateau there, and reacting to every bluff against the main army
    would hand it towns for nothing. It is a *second*, much tighter margin
    for troops that are not part of the main army, on the same logic as the
    human game's "march everyone out of Belmar while it was still just
    accumulating": leaving an exposed outpost early is cheap, and one left
    standing is an offer MistBot's own re-bidding will eventually take.

    isolated_margin  deficit that triggers rescue-or-withdraw for a garrison
                      outside the main component. Default 1: react to any
                      real threat at all, since there is no safety in numbers
                      to wait for out there.
    """

    def __init__(self, rng: random.Random | None = None, retreat_margin: int = 5,
                 isolated_margin: int = 1):
        super().__init__(rng, retreat_margin=retreat_margin)
        self.isolated_margin = isolated_margin

    @staticmethod
    def _home_component(board: GameState) -> frozenset[str]:
        """The Empire's main body: the network holding the most troops.

        Ties broken by town count and then by which set of ids sorts lowest,
        purely so the choice is deterministic — neither tie-break means
        anything on its own, and real ties are rare enough not to matter.
        """
        components = empire_components(board)
        if not components:
            return frozenset()
        best = max(
            components,
            key=lambda c: (troops_in(board, c), len(c), tuple(sorted(c, reverse=True))),
        )
        return frozenset(best)

    def _moves(self, board: GameState) -> list[tuple[str, str, int]]:
        # Identical to GlobEmpire._moves except it derives `home` once, up
        # front, and hands it to `_rescue` so trouble can be judged by a
        # different standard depending on whether help is reachable.
        origin = {tid: t.troops for tid, t in board.towns.items()}
        departed: dict[str, int] = {}
        moves: list[tuple[str, str, int]] = []
        tolerated = _overage(board)
        home = self._home_component(board)

        def available(town_id: str) -> int:
            return origin[town_id] - departed.get(town_id, 0)

        def spare(town_id: str) -> int:
            town = board.towns[town_id]
            keep = self._garrison_needed(board, town)
            return max(0, min(available(town_id), town.troops - keep))

        def commit(src: str, dst: str, quantity: int) -> bool:
            board.towns[src].troops -= quantity
            board.towns[dst].troops += quantity
            if _overage(board) > tolerated:
                board.towns[src].troops += quantity
                board.towns[dst].troops -= quantity
                return False
            departed[src] = departed.get(src, 0) + quantity
            moves.append((src, dst, quantity))
            return True

        self._rescue(board, available, spare, commit, home)
        self._expand(board, commit, spare)
        return moves

    def _rescue(self, board, available, spare, commit, home=frozenset()) -> None:
        """As GlobEmpire, but a garrison outside `home` uses `isolated_margin`.

        Everything past the threshold — all-or-nothing reinforcement,
        withdrawing rather than standing half-fed — is unchanged; only the
        deficit that counts as "trouble" differs by whether the rest of the
        army is in reach.
        """
        troop_presence = board.scenario.unit.presence

        def deficit(town) -> int:
            return self.worst_case(board, town) - town.troops * troop_presence

        def margin_for(town_id: str) -> int:
            return (self.retreat_margin if component_of(board, town_id) == home
                    else self.isolated_margin)

        troubled = sorted(
            (t for t in board.unresolved
             if t.troops > 0 and deficit(t) >= margin_for(t.id)),
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


# ---------------------------------------------------------------------------
# The Insurgency that plays the map
# ---------------------------------------------------------------------------

class MistBot:
    """The Insurgency bot that reads the board rather than the arithmetic.

    `HeuristicInsurgency` picks the richest garrison it can see and piles
    presence onto it. This one asks three questions in order, and every answer
    is a question about *geography* — where the troops are, not how many points
    are on the table:

    * **Resolve anything already won**, richest first. The rebels placed every
      card and troops are public, so a win is certain by inspection — there is
      no `worst_case` here and nothing to gamble on. Ties inside a score go to
      the town touching the most occupied towns, because that is the one the
      Empire is likeliest to reinforce before it can be cashed again.
    * **Get ahead where the Empire is thin on support.** Spend the fewest cards
      that clear the garrison by one, and prefer the town with the fewest
      troops *around* it: a lead only survives if the Empire cannot walk a
      relief column into it before the next turn.
    * **Spend the bluffs where a pile will be believed**, one at a time,
      re-reading the board between each. An empty town beside a lot of troops
      first — that is where the Empire is about to arrive and where a pile
      costs it a look or a detour — and never an empty town with no troops near
      it, which nobody will ever walk into and which therefore says nothing.

    The whole hand goes out every turn (Decision 6), so the last rule is a
    fallback chain rather than a preference: something has to take the card.

    `rng` is accepted for interface compatibility and deliberately unused.
    Every tie here breaks on the board — adjacency, then town id — so the bot
    is a pure function of the position and a disagreement with the PHP port is
    a real disagreement rather than a different random seed.
    """

    def __init__(self, rng: random.Random | None = None):
        self.rng = rng or random.Random()

    def choose(self, state: GameState) -> InsurgencyTurn:
        # Planned against a copy with the resolution already applied, in the
        # engine's own order (Decision 4): a town resolved now is closed to
        # placement, and a garrison the rebels just took is off the board and
        # out of every adjacency count below.
        board = state.clone()

        resolve = self._resolution(board)
        if resolve is not None:
            resolve_town(board, resolve, Side.INSURGENCY)

        return InsurgencyTurn(placements=self._placements(board), resolve=resolve)

    # -- phase 1: resolve ----------------------------------------------------

    def _resolution(self, board: GameState) -> str | None:
        """Cash every town already won, richest first; a 0-point win counts.

        Beating an empty garrison scores nothing, but it still takes the town
        out of the game, and a town the Empire can never stand in is a town it
        can never draw supply from. The mirror of GlobEmpire taking an empty
        town for the same reason.
        """
        best, best_key = None, None
        for town in board.unresolved:
            if town.card_count == 0:
                continue
            troop_presence = board.troop_presence_in(town.id)
            if board.card_presence_in(town.id) <= troop_presence:
                continue  # the Empire wins ties, so a draw is not a win
            key = (troop_presence, _adjacent_occupied(board, town), town.id)
            if best_key is None or key > best_key:
                best, best_key = town.id, key
        return best

    # -- phase 2: placement --------------------------------------------------

    def _placements(self, board: GameState) -> dict[str, list[int]]:
        """Where the whole hand goes, deciding one commitment at a time.

        `board` is mutated as cards are committed, so every choice after the
        first sees the pile the last one built. That is what makes the bluff
        rule spread: a town stops being empty the moment it is seeded.
        """
        if not board.unresolved:
            return {}  # the resolution closed the last town; nothing may be placed

        placements: dict[str, list[int]] = {}

        def place(town_id: str, index: int) -> None:
            placements.setdefault(town_id, []).append(index)
            board.towns[town_id].pile.insert(0, board.hand[index])

        # Biggest first: it reaches a threshold with the fewest cards, which
        # leaves the rest of the hand free to threaten somewhere else.
        real = sorted(
            (i for i, c in enumerate(board.hand) if c.presence > 0),
            key=lambda i: (-board.hand[i].presence, i),
        )
        bluffs = [i for i, c in enumerate(board.hand) if c.presence == 0]

        # 2a. Take the lead in every town the hand can still afford to lead in.
        best_target = None
        while real:
            target = self._lead_target(board, real)
            if target is None:
                break
            if best_target is None:
                best_target = target
            needed = (board.troop_presence_in(target)
                      - board.card_presence_in(target) + 1)
            while real and needed > 0:
                index = real.pop(0)
                needed -= board.hand[index].presence
                place(target, index)

        # 2b. Nothing left to flip: seed where the Empire has enough troops
        #     next door to come and make a fight of it. One card each — a
        #     second card on the same town says nothing new.
        while real:
            target = self._arrival_target(board)
            if target is None:
                break
            place(target, real.pop(0))

        # 2c. Anything still in hand deepens the town we led in first.
        if best_target is not None:
            while real:
                place(best_target, real.pop(0))

        # 3. Bluffs, and any real card with nowhere better, one at a time.
        for index in real + bluffs:
            place(self._bluff_target(board), index)

        return placements

    def _lead_target(self, board: GameState, real: list[int]) -> str | None:
        """The garrisoned town to take the lead in next, or None.

        Affordable means the presence still in hand covers the whole deficit:
        half a lead is a donation, since the Empire scores every card in a town
        it wins.
        """
        budget = sum(board.hand[i].presence for i in real)
        best, best_key = None, None
        for town in board.unresolved:
            if town.troops <= 0:
                continue
            deficit = (board.troop_presence_in(town.id)
                       - board.card_presence_in(town.id) + 1)
            if deficit <= 0 or deficit > budget:
                continue
            # Fewest troops in reach first — a lead the Empire cannot answer
            # next turn. Then the richest garrison, since that is the score.
            key = (_adjacent_troops(board, town),
                   -board.troop_presence_in(town.id),
                   town.id)
            if best_key is None or key < best_key:
                best, best_key = town.id, key
        return best

    def _arrival_target(self, board: GameState) -> str | None:
        """An empty town next to a garrison big enough to spare a troop.

        One troop marching out of a town of one abandons it, so a lone troop
        is not really a neighbour. Two is the smallest garrison that can visit.
        """
        best, best_key = None, None
        for town in board.unresolved:
            if town.troops > 0 or town.card_count > 0:
                continue
            if not any(board.towns[n].troops > 1 for n in town.neighbors):
                continue
            key = (-_adjacent_troops(board, town), town.id)
            if best_key is None or key < best_key:
                best, best_key = town.id, key
        return best

    def _bluff_target(self, board: GameState) -> str:
        """Where the next single card goes, re-read from the board each time.

        Empty towns beside troops first, tallest concentration first; then the
        closest thing to a tied town, where one more card in the pile changes
        what the Empire believes it is looking at. An empty town with no troops
        anywhere near it is never chosen — nobody will walk into it, so the
        bluff is spent on an audience of nobody — unless it is all that is
        left, in which case the nearest one to a troop takes it.
        """
        empty_beside_troops = [
            t for t in board.unresolved
            if t.troops == 0 and t.card_count == 0 and _adjacent_troops(board, t) > 0
        ]
        if empty_beside_troops:
            return min(empty_beside_troops,
                       key=lambda t: (-_adjacent_troops(board, t), t.id)).id

        # Towns where the two sides are closest: a pile that already looks like
        # a fight is the one an extra card can tip in the Empire's reading.
        speaks = [
            t for t in board.unresolved
            if t.troops > 0 or t.card_count > 0 or _adjacent_troops(board, t) > 0
        ]
        if speaks:
            return min(speaks, key=lambda t: (
                abs(board.card_presence_in(t.id) - board.troop_presence_in(t.id)),
                -_adjacent_troops(board, t),
                t.id,
            )).id

        # Every open town is empty and out of reach of any troop. Forced, so
        # take the one the Empire would reach first.
        hops = _hops_to_troops(board)
        return min(board.unresolved,
                   key=lambda t: (hops.get(t.id, len(board.towns)), t.id)).id


class Mist2Insurgency(MistBot):
    """MistBot, aimed at the garrison rather than away from it (issue #18).

    MistBot's lead target picks the town with the *fewest* troops nearby
    first, on the theory that a lead only survives if the Empire cannot walk
    a relief column into it. Against a human Empire that never breaks up its
    army, that rule always points at the far edge of the map — the only
    place with few adjacent troops is wherever the Empire is not — so the
    "wins" it found there were all against towns worth nothing: three human
    games saw six, four and one uncontested empty towns resolve for zero
    while the Empire's actual garrison went untouched (issue #18, games 1
    and 2).

    The third game showed the alternative works. The Empire reinforced Kirn
    from one troop to three, and MistBot's own rule cleared it a second time
    anyway, for a bigger prize at no extra cost: "a bigger garrison is a
    bigger prize, and clearing it by one costs the rebels no more than
    clearing a small one by one." So this bot leads with the richest
    garrison it can afford and uses fewest-troops-in-reach only to break a
    tie between equally rich targets, rather than as the deciding question.
    It does not stop attacking defended ground; it stops avoiding it.
    """

    def _lead_target(self, board: GameState, real: list[int]) -> str | None:
        budget = sum(board.hand[i].presence for i in real)
        best, best_key = None, None
        for town in board.unresolved:
            if town.troops <= 0:
                continue
            deficit = (board.troop_presence_in(town.id)
                       - board.card_presence_in(town.id) + 1)
            if deficit <= 0 or deficit > budget:
                continue
            # Richest garrison first — that is the score. Fewest troops in
            # reach only breaks a tie between two equally valuable targets.
            key = (-board.troop_presence_in(town.id),
                   _adjacent_troops(board, town),
                   town.id)
            if best_key is None or key < best_key:
                best, best_key = town.id, key
        return best


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


def _adjacent_troops(state: GameState, town) -> int:
    """Troops standing in the towns next door, added up.

    Resolved towns count: an Empire garrison survives a town it won
    (Decision 3) and can march out of it like any other.
    """
    return sum(state.towns[n].troops for n in town.neighbors)


def _adjacent_occupied(state: GameState, town) -> int:
    """How many towns next door hold any troops at all.

    Deliberately a count of towns where `_adjacent_troops` is a sum of troops:
    one asks how many directions a threat can come from, the other how heavy
    it would be.
    """
    return sum(1 for n in town.neighbors if state.towns[n].troops > 0)


def _hops_to_troops(state: GameState) -> dict[str, int]:
    """Distance from every town to the nearest troops, by road."""
    distance = {tid: 0 for tid, t in state.towns.items() if t.troops > 0}
    frontier = list(distance)
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
    "mist": (GlobEmpire, MistBot),
    "glob2": (Glob2Empire, HeuristicInsurgency),
    "mist2": (GlobEmpire, Mist2Insurgency),
}

EMPIRE_BOTS = {
    "random": RandomEmpire,
    "heuristic": HeuristicEmpire,
    "glob": GlobEmpire,
    "glob2": Glob2Empire,
}

INSURGENCY_BOTS = {
    "random": RandomInsurgency,
    "heuristic": HeuristicInsurgency,
    "mist": MistBot,
    "mist2": Mist2Insurgency,
}
