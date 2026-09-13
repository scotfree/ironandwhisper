"""Pure rules engine for Iron and Whisper.

This module is the executable specification of the rules. It performs no I/O,
takes its randomness from an injected Random instance, and is intended to be
ported to PHP more or less directly.

State is mutated in place for speed; call GameState.clone() if you need to
search over hypothetical futures.
"""

from __future__ import annotations

import random
from dataclasses import dataclass, field
from enum import Enum
from itertools import count

from .config import Scenario


class Side(str, Enum):
    EMPIRE = "empire"
    INSURGENCY = "insurgency"

    @property
    def other(self) -> "Side":
        return Side.INSURGENCY if self is Side.EMPIRE else Side.EMPIRE


class IllegalMove(Exception):
    """Raised when a submitted turn violates the rules."""


@dataclass
class Card:
    """One card in the Insurgency deck.

    `uid` identifies this physical card for the lifetime of the game, which is
    what lets the Empire remember "I looked at that one, it was a dummy" even
    after the pile has rotated underneath it.
    """
    uid: int
    type_id: str
    presence: int

    def __repr__(self) -> str:
        return f"<{self.type_id}#{self.uid}>"


@dataclass
class Town:
    id: str
    label: str
    neighbors: tuple[str, ...]

    # Face-down pile. Index 0 is the TOP: new cards are placed on top, and a
    # look flips the top card into `revealed`.
    pile: list[Card] = field(default_factory=list)

    # Face up beside the pile, where a look puts what it found. Public to both
    # players: the Insurgency could always compute what the Empire had seen, so
    # putting the cards on the table costs it nothing and saves both sides the
    # bookkeeping. These still count in full at resolution.
    revealed: list[Card] = field(default_factory=list)

    # Empire troops standing here. Resolution spends them: they are removed
    # from play, which is what makes committing to a town cost something.
    troops: int = 0

    resolved: bool = False
    winner: Side | None = None

    # Recorded at resolution so the board stays a readable history.
    resolved_card_presence: int = 0
    resolved_troop_presence: int = 0

    # How many of this town's troops are forecast to starve, worked out at the
    # end of the Empire's last turn. A warning, not a reservation: the loss is
    # recomputed from the live board when it actually falls, so repairing the
    # supply line during the turn of grace cancels it entirely.
    starving: int = 0

    @property
    def is_occupied(self) -> bool:
        """Whether the Empire may generate here."""
        return self.troops > 0

    @property
    def cards(self) -> list[Card]:
        """Everything committed to this town, face down or face up."""
        return self.pile + self.revealed

    @property
    def card_count(self) -> int:
        return len(self.pile) + len(self.revealed)


@dataclass
class InsurgencyTurn:
    """placements maps town id -> indices into the current hand.

    Every card in hand must be placed somewhere (Decision 6).
    """
    placements: dict[str, list[int]] = field(default_factory=dict)
    resolve: str | None = None


@dataclass
class EmpireTurn:
    """One Empire turn: build, march, look, and maybe resolve.

    `produce` maps a production town to how many troops to raise there. Troops
    appear where they are built and have to march from there.

    `disband` optionally names where to take attrition losses from. Anything not
    specified is taken from the largest garrisons, so a turn is always legal.

    Looks are not listed: every troop that did not move peeks automatically,
    since peeking is free, always available to a stationary troop, and never
    disadvantageous. Making it an explicit choice would add an action with no
    decision in it.
    """
    produce: dict[str, int] = field(default_factory=dict)
    moves: list[tuple[str, str, int]] = field(default_factory=list)
    resolve: str | None = None
    disband: dict[str, int] = field(default_factory=dict)


@dataclass
class GameState:
    scenario: Scenario
    towns: dict[str, Town]
    deck: list[Card]
    hand: list[Card]
    scores: dict[Side, int]
    to_move: Side
    round_number: int = 1
    game_over: bool = False

    # Human-readable record of what happened, for notebooks and debugging.
    log: list[str] = field(default_factory=list)

    def clone(self) -> "GameState":
        return GameState(
            scenario=self.scenario,
            towns={
                tid: Town(
                    id=t.id, label=t.label, neighbors=t.neighbors,
                    pile=list(t.pile), revealed=list(t.revealed),
                    troops=t.troops, resolved=t.resolved,
                    winner=t.winner, resolved_card_presence=t.resolved_card_presence,
                    resolved_troop_presence=t.resolved_troop_presence,
                )
                for tid, t in self.towns.items()
            },
            deck=list(self.deck),
            hand=list(self.hand),
            scores=dict(self.scores),
            to_move=self.to_move,
            round_number=self.round_number,
            game_over=self.game_over,
            log=list(self.log),
        )

    # -- convenience views -------------------------------------------------

    @property
    def unresolved(self) -> list[Town]:
        return [t for t in self.towns.values() if not t.resolved]

    @property
    def resolved(self) -> list[Town]:
        return [t for t in self.towns.values() if t.resolved]

    def card_presence_in(self, town_id: str) -> int:
        """True total presence in a pile. Omniscient: the Empire cannot see this."""
        return sum(c.presence for c in self.towns[town_id].cards)

    def troop_presence_in(self, town_id: str) -> int:
        return self.towns[town_id].troops * self.scenario.unit.presence

    def total_troops(self) -> int:
        return sum(t.troops for t in self.towns.values())


# ---------------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------------

def new_game(scenario: Scenario, rng: random.Random | None = None) -> GameState:
    rng = rng or random.Random()

    towns = {
        t.id: Town(id=t.id, label=t.label, neighbors=scenario.map.neighbors[t.id])
        for t in scenario.map.towns
    }

    uids = count(1)
    deck: list[Card] = []
    for type_id, quantity in scenario.deck.items():
        card_type = scenario.card_types[type_id]
        for _ in range(quantity):
            deck.append(Card(uid=next(uids), type_id=type_id,
                             presence=card_type.presence))
    rng.shuffle(deck)

    for town_id, quantity in scenario.empire_start.items():
        if town_id not in towns:
            raise ValueError(f"empire_start names unknown town {town_id!r}")
        towns[town_id].troops = quantity

    return GameState(
        scenario=scenario,
        towns=towns,
        deck=deck,
        hand=[],
        scores={Side.EMPIRE: 0, Side.INSURGENCY: 0},
        to_move=scenario.first_player,
    )


# ---------------------------------------------------------------------------
# Resolution
# ---------------------------------------------------------------------------

def resolve_town(state: GameState, town_id: str, declared_by: Side | None) -> Side:
    """Flip a pile, score it, and freeze the town.

    `declared_by` is None for the simultaneous resolution triggered by deck
    exhaustion, where the presence requirement does not apply.
    """
    town = state.towns[town_id]
    if town.resolved:
        raise IllegalMove(f"{town_id} is already resolved")

    # Both sides bring presence to a town; only what carries it differs.
    card_presence = sum(c.presence for c in town.cards)
    troop_presence = town.troops * state.scenario.unit.presence

    if troop_presence > card_presence:
        winner = Side.EMPIRE
    elif card_presence > troop_presence:
        winner = Side.INSURGENCY
    else:
        winner = Side.EMPIRE if state.scenario.empire_wins_ties else Side.INSURGENCY

    # You score only what you take off the opponent.
    if winner is Side.EMPIRE:
        state.scores[Side.EMPIRE] += card_presence
    else:
        state.scores[Side.INSURGENCY] += troop_presence

    town.resolved = True
    town.winner = winner
    town.resolved_card_presence = card_presence
    town.resolved_troop_presence = troop_presence

    # The flip is public whatever happens next.
    town.revealed.extend(town.pile)
    town.pile.clear()

    # The loser's commitment is taken off the board and scored; the winner's
    # stays. An Empire that holds a town keeps its garrison, so the town goes on
    # carrying supply and, if it can, building.
    if winner is Side.EMPIRE:
        town.revealed.clear()
    else:
        town.troops = 0

    who = "auto" if declared_by is None else declared_by.value
    state.log.append(
        f"R{state.round_number}: {town.label} resolved ({who}) — "
        f"rebels {card_presence} presence, Empire {troop_presence} presence — "
        f"{winner.value} takes it, "
        f"scoring {card_presence if winner is Side.EMPIRE else troop_presence}"
    )
    return winner


def empire_components(state: GameState) -> list[set[str]]:
    """The Empire's supply networks.

    A town is in the network if the Empire stands in it, and two occupied towns
    are linked if the map links them. Resolution does not matter: a town the
    Empire won and still garrisons carries supply exactly like any other.

    Cutting a network in two gives two smaller ceilings, which is the whole
    point of the Insurgency attacking a junction.
    """
    occupied = {t.id for t in state.towns.values() if t.troops > 0}
    seen: set[str] = set()
    components: list[set[str]] = []

    for town_id in sorted(occupied):
        if town_id in seen:
            continue
        component = {town_id}
        frontier = [town_id]
        while frontier:
            current = frontier.pop()
            for neighbor in state.towns[current].neighbors:
                if neighbor in occupied and neighbor not in component:
                    component.add(neighbor)
                    frontier.append(neighbor)
        seen |= component
        components.append(component)

    return components


def component_of(state: GameState, town_id: str) -> set[str]:
    for component in empire_components(state):
        if town_id in component:
            return component
    return set()


def town_supply(state: GameState, town_id: str) -> int:
    """What a town gives the Empire, which is nothing once the rebels have won it.

    An Insurgency victory denies the ground permanently. The Empire may march
    back in — the town is resolved, so it can never be contested again — and it
    will hold a line, but it will never feed one. That is what makes taking a
    town worth something lasting to a side that cannot build a network of its
    own: it does not capture supply, it destroys it.
    """
    town = state.towns[town_id]
    if town.resolved and town.winner is Side.INSURGENCY:
        return 0
    return state.scenario.town_supply[town_id]


def town_production(state: GameState, town_id: str) -> int:
    """As with supply: a town the rebels took never builds for the Empire again."""
    town = state.towns[town_id]
    if town.resolved and town.winner is Side.INSURGENCY:
        return 0
    return state.scenario.town_production[town_id]


def ceiling(state: GameState, component: set[str]) -> int:
    """How many troops a network can keep standing."""
    supply = sum(town_supply(state, tid) for tid in component)
    return supply // state.scenario.supply_per_troop


def troops_in(state: GameState, component: set[str]) -> int:
    return sum(state.towns[tid].troops for tid in component)


def empire_holds(state: GameState, town_id: str) -> bool:
    """Whether the town is the Empire's to build in — held, or taken.

    Standing in a town counts, and so does having *won* it: a town the Empire
    took at a resolution is permanently its ground, so it goes on building even
    once the garrison has marched away or starved. A town that is merely empty
    is nobody's, which is what stops the Empire drawing troops out of a factory
    it has never been near.
    """
    town = state.towns[town_id]
    if town.resolved:
        return town.winner is Side.EMPIRE
    return town.troops > 0


def production_sites(state: GameState) -> list[str]:
    """Towns that can build for the Empire.

    A *garrison* is not required, only presence or ownership. Requiring troops
    on the spot was a chicken-and-egg: an Empire that lost the last troop in a
    factory it had already won could never raise another there. What stops a
    town building is nobody holding it, or the rebels having won it — denial is
    permanent (Decision 2), and taking a production town is the only way to
    switch it off for good.
    """
    cost = state.scenario.production_cost
    return [
        t.id for t in state.towns.values()
        if town_production(state, t.id) >= cost > 0 and empire_holds(state, t.id)
    ]


def production_capacity(state: GameState, town_id: str) -> int:
    """How many troops this town could raise this turn, ignoring the ceiling."""
    cost = state.scenario.production_cost
    if cost <= 0 or not empire_holds(state, town_id):
        return 0
    return town_production(state, town_id) // cost


def empire_is_eliminated(state: GameState) -> bool:
    """Whether the Empire can never act again.

    No troops on the board and no town left that will build for it: there is no
    move it could make for the rest of the game, so there is no game left.
    """
    return (
        all(town.troops == 0 for town in state.towns.values())
        and not production_sites(state)
    )


def headroom(state: GameState, town_id: str) -> int:
    """Spare ceiling in the network this town belongs to."""
    component = component_of(state, town_id)
    if not component:
        return 0
    return max(0, ceiling(state, component) - troops_in(state, component))


def _can_declare(state: GameState, town_id: str, side: Side) -> bool:
    """Presence requirement (Decision 5)."""
    if town_id not in state.towns:
        return False
    town = state.towns[town_id]
    if town.resolved:
        return False
    if side is Side.EMPIRE:
        return town.troops > 0
    return town.card_count > 0


def legal_resolutions(state: GameState, side: Side) -> list[str]:
    return [t.id for t in state.unresolved if _can_declare(state, t.id, side)]


# ---------------------------------------------------------------------------
# Turn application
# ---------------------------------------------------------------------------

def apply_insurgency_turn(state: GameState, turn: InsurgencyTurn) -> None:
    if state.to_move is not Side.INSURGENCY:
        raise IllegalMove("not the Insurgency's turn")

    # 1. Resolve first, against the board as the opponent left it (Decision 4).
    #    Declaring before you act is what stops a turn being "place exactly
    #    enough, then cash out" with nothing the other side can do about it.
    if turn.resolve is not None:
        if not _can_declare(state, turn.resolve, Side.INSURGENCY):
            raise IllegalMove(
                f"Insurgency cannot declare {turn.resolve}: no cards there, "
                f"or already resolved"
            )
        resolve_town(state, turn.resolve, Side.INSURGENCY)

    # 2. Place. The whole hand must go out (Decision 6) — unless resolving just
    #    closed the last open town, in which case there is nowhere legal left
    #    and the game is about to end anyway.
    if not state.unresolved:
        if turn.placements:
            raise IllegalMove("no unresolved towns left to place into")
        state.to_move = Side.EMPIRE
        return

    placed_indices: list[int] = []
    for town_id, indices in turn.placements.items():
        if town_id not in state.towns:
            raise IllegalMove(f"unknown town {town_id!r}")
        if state.towns[town_id].resolved:
            raise IllegalMove(f"{town_id} is resolved; no cards may be placed there")
        placed_indices.extend(indices)

    if sorted(placed_indices) != list(range(len(state.hand))):
        raise IllegalMove(
            f"the entire hand must be placed: got indices {sorted(placed_indices)}, "
            f"hand has {len(state.hand)} cards"
        )

    # Place onto the top of each pile.
    for town_id, indices in turn.placements.items():
        town = state.towns[town_id]
        for i in indices:
            town.pile.insert(0, state.hand[i])
    total_placed = len(placed_indices)
    state.hand = []

    if total_placed:
        summary = ", ".join(
            f"{len(ix)}->{state.towns[tid].label}"
            for tid, ix in turn.placements.items() if ix
        )
        state.log.append(f"R{state.round_number}: Insurgency places {summary}")

    state.to_move = Side.EMPIRE


def apply_empire_turn(state: GameState, turn: EmpireTurn) -> None:
    if state.to_move is not Side.EMPIRE:
        raise IllegalMove("not the Empire's turn")

    scenario = state.scenario

    # 1. Resolve first, against the board as the opponent left it (Decision 4).
    #    You cannot march in and cash out in the same turn: whatever you commit
    #    has to survive the Insurgency's reply before you can collect on it.
    if turn.resolve is not None:
        if not _can_declare(state, turn.resolve, Side.EMPIRE):
            raise IllegalMove(
                f"Empire cannot declare {turn.resolve}: no troops there, "
                f"or already resolved"
            )
        resolve_town(state, turn.resolve, Side.EMPIRE)

    # 2. Build. A town can raise troops if the Empire holds it and it can
    #    produce. Supply is not checked here: building past the ceiling is
    #    allowed, and attrition settles it — with a turn of grace, so an
    #    overshoot is a stated risk rather than an instant loss, and you may
    #    build now and march out to the supply that will feed them.
    for town_id, count in turn.produce.items():
        if count <= 0:
            raise IllegalMove("produce count must be positive")
        town = state.towns.get(town_id)
        if town is None:
            raise IllegalMove(f"unknown town {town_id!r}")
        if not empire_holds(state, town_id):
            raise IllegalMove(f"cannot build at {town_id}: the Empire does not hold it")
        if count > production_capacity(state, town_id):
            raise IllegalMove(
                f"{town_id} can build {production_capacity(state, town_id)}, asked for {count}"
            )
        town.troops += count
        state.log.append(f"R{state.round_number}: Empire builds {count} at {town.label}")

    # 3. Move. Record departures first so we can work out who stayed still.
    departed: dict[str, int] = {}
    for src, dst, quantity in turn.moves:
        if quantity <= 0:
            raise IllegalMove("move count must be positive")
        if src not in state.towns or dst not in state.towns:
            raise IllegalMove(f"unknown town in move {src!r} -> {dst!r}")
        source, dest = state.towns[src], state.towns[dst]
        # Resolved towns are ordinary terrain for movement: pacified, passable,
        # simply no longer contestable. Troops move freely in and out.
        if dst not in source.neighbors:
            raise IllegalMove(f"{dst} is not adjacent to {src}")
        departed[src] = departed.get(src, 0) + quantity
        if departed[src] > source.troops:
            raise IllegalMove(
                f"{src} has {source.troops} troops, tried to move {departed[src]}"
            )

    # Movement is simultaneous: everyone leaves, then everyone arrives.
    for src, quantity in departed.items():
        state.towns[src].troops -= quantity
    for src, dst, quantity in turn.moves:
        state.towns[dst].troops += quantity

    if turn.moves:
        summary = ", ".join(
            f"{n} {state.towns[a].label}->{state.towns[b].label}"
            for a, b, n in turn.moves
        )
        state.log.append(f"R{state.round_number}: Empire moves {summary}")

    # 4. Look. Every troop that did not move peeks; peeks stack per town.
    for town in state.towns.values():
        if town.resolved or not town.pile:  # nothing left face down to read
            continue
        # town.troops already reflects both departures and arrivals, so the
        # troops that held still are whatever is left once arrivals are removed.
        # A troop generated this turn counts as stationary: it did not move.
        arrived = sum(q for _, d, q in turn.moves if d == town.id)
        stationary = town.troops - arrived
        if stationary <= 0:
            continue
        _peek(state, town, stationary * scenario.unit.peek)

    # 5. Starve anything the networks could not supply as of last turn, and
    #    warn about anything they cannot supply now.
    _attrition(state, turn.disband)

    state.to_move = Side.INSURGENCY
    state.round_number += 1


def attrition_plan(state: GameState, disband: dict[str, int]) -> dict[str, int]:
    """Where a network's excess would be taken from, if it were taken now.

    Towns are worked in order of garrison size, largest first, so the loss
    falls on the biggest stacks — with anything the Empire named in `disband`
    moved to the front. Within a town there is no choice to make: a town holds a
    count of troops, not troops.
    """
    plan: dict[str, int] = {}

    for component in empire_components(state):
        over = troops_in(state, component) - ceiling(state, component)
        if over <= 0:
            continue

        # Take what the Empire asked for first, then the largest garrisons, so
        # a turn is always legal even if it names nowhere.
        order = [tid for tid in sorted(component) if disband.get(tid)]
        order += sorted(component, key=lambda tid: -state.towns[tid].troops)

        taken = 0
        for town_id in order:
            if taken >= over:
                break
            already = plan.get(town_id, 0)
            available = state.towns[town_id].troops - already
            # A town named in `disband` gives up that many and no more; one that
            # is not named will give up everything it has if it comes to it.
            cap = disband[town_id] - already if town_id in disband else available
            take = min(available, over - taken, max(0, cap))
            if take > 0:
                plan[town_id] = already + take
                taken += take

    return plan


def _attrition(state: GameState, disband: dict[str, int]) -> None:
    """Starve what was already warned about, then warn about the rest.

    A network that cannot feed its troops does not starve them at once. It is
    marked, the Empire gets its next turn to do something about it, and only if
    it is still short at the end of *that* turn does anyone die. Immediate
    attrition made massing self-defeating in a way nobody could see coming: the
    troops you march in are fed by the towns you marched them out of, so
    concentrating destroys the supply that would have fed the concentration, and
    the loss landed after the player had stopped looking at the board.

    With a turn of grace the same move becomes a decision. Mass this turn,
    resolve at full presence next turn — resolution comes first (Decision 4) —
    then either spread back out to re-occupy the supply or accept the loss.

    The mark is a forecast, never a reservation: what actually falls is
    recomputed here from the board as it stands, so repairing the line cancels
    it and moving troops moves where it lands.

    Starved troops score for the Insurgency. That is not a special rule, it is
    the general one — the Insurgency scores every Empire troop that leaves the
    board, whether it was beaten off it or starved off it. Cutting a supply line
    is a way of taking troops, so it pays like one.
    """
    plan = attrition_plan(state, disband)

    # Apply only to networks that were already carrying a warning. A network
    # that has just gone short is marked below instead.
    starved = 0
    for component in empire_components(state):
        if not any(state.towns[tid].starving for tid in component):
            continue
        for town_id in component:
            take = plan.get(town_id, 0)
            if take:
                state.towns[town_id].troops -= take
                starved += take

    if starved:
        state.scores[Side.INSURGENCY] += starved * state.scenario.unit.presence
        state.log.append(
            f"R{state.round_number}: {starved} Empire troops starve for want of supply"
        )

    # Whatever is still short is next turn's warning. Anything that came good
    # is cleared, which is what makes repairing the line pay.
    forecast = attrition_plan(state, disband)
    for town in state.towns.values():
        town.starving = forecast.get(town.id, 0)


def _peek(state: GameState, town: Town, look_count: int) -> None:
    """Flip the top card of the face-down pile face up (Decision 8).

    The card leaves the pile and sits beside it, so a look never lands on a
    card that is already known and the pile is only ever unknowns. There is
    nothing to cap and no rotation to track: when the pile is empty the
    garrison has read the town, and further looks do nothing until the
    Insurgency adds more.
    """
    for _ in range(min(look_count, len(town.pile))):
        town.revealed.append(town.pile.pop(0))


# ---------------------------------------------------------------------------
# Game flow
# ---------------------------------------------------------------------------

def prepare_turn(state: GameState) -> None:
    """Run start-of-turn upkeep and detect the end of the game.

    Call this before asking a player for their turn.
    """
    if state.game_over:
        return

    # The board can run out before the deck does.
    if not state.unresolved:
        _end_game(state, "every town resolved")
        return

    # So can the Empire. Once it has no troops and nothing that will build any,
    # the rest of the game is the Insurgency placing cards nobody will contest.
    if empire_is_eliminated(state):
        _end_game(state, "the Empire is eliminated")
        return

    if state.to_move is Side.INSURGENCY:
        want = state.scenario.hand_size - len(state.hand)
        drawn = min(want, len(state.deck))
        for _ in range(drawn):
            state.hand.append(state.deck.pop())

        # Deck exhausted and nothing left to place: the game ends and every
        # remaining town resolves at once (Decision 1).
        if not state.hand:
            _end_game(state, "deck exhausted")
            return


def town_is_uncontested(town: Town) -> bool:
    """Whether nobody has committed anything here — no troops, no cards."""
    return town.troops == 0 and town.card_count == 0


def _end_game(state: GameState, reason: str) -> None:
    """Resolve everything anybody committed to, all at once.

    A town neither side ever set foot in is left open. Presence is required to
    declare a resolution (Decision 5), and that holds for the sweep too: such a
    town is worth nothing to either side by definition, and handing it to
    whoever wins ties is noise on the board and in the log.
    """
    state.log.append(f"R{state.round_number}: game ends — {reason}")
    for town in list(state.unresolved):
        if town_is_uncontested(town):
            continue
        resolve_town(state, town.id, declared_by=None)
    state.game_over = True


def winner(state: GameState) -> Side | None:
    """Winner, or None for a draw. Only meaningful once game_over."""
    empire, insurgency = state.scores[Side.EMPIRE], state.scores[Side.INSURGENCY]
    if empire > insurgency:
        return Side.EMPIRE
    if insurgency > empire:
        return Side.INSURGENCY
    return None


def play_game(scenario: Scenario, empire_bot, insurgency_bot,
              rng: random.Random | None = None) -> GameState:
    """Run one complete game between two bots and return the final state."""
    state = new_game(scenario, rng)
    guard = 0
    while True:
        prepare_turn(state)
        if state.game_over:
            return state
        bot = empire_bot if state.to_move is Side.EMPIRE else insurgency_bot
        turn = bot.choose(state)
        if state.to_move is Side.EMPIRE:
            apply_empire_turn(state, turn)
        else:
            apply_insurgency_turn(state, turn)

        guard += 1
        if guard > 10_000:
            raise RuntimeError("game failed to terminate; check the clock rules")
