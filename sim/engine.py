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
    influence: int

    def __repr__(self) -> str:
        return f"<{self.type_id}#{self.uid}>"


@dataclass
class Town:
    id: str
    label: str
    neighbors: tuple[str, ...]

    # Face-down pile. Index 0 is the TOP of the pile: new cards are placed on
    # top, and peeking draws from the top and returns to the bottom.
    pile: list[Card] = field(default_factory=list)

    # Empire troops standing here. Resolution spends them: they are removed
    # from play, which is what makes committing to a town cost something.
    troops: int = 0

    resolved: bool = False
    winner: Side | None = None

    # Recorded at resolution so the board stays a readable history.
    resolved_influence: int = 0
    resolved_strength: int = 0

    @property
    def has_empire_presence(self) -> bool:
        """Whether the Empire may generate here."""
        return self.troops > 0


@dataclass
class InsurgencyTurn:
    """placements maps town id -> indices into the current hand.

    Every card in hand must be placed somewhere (Decision 6).
    """
    placements: dict[str, list[int]] = field(default_factory=dict)
    resolve: str | None = None


@dataclass
class EmpireTurn:
    """moves is a list of (from_town, to_town, count) troop transfers.

    Looks are not listed: every troop that did not move peeks automatically,
    since peeking is free, always available to a stationary troop, and never
    disadvantageous. Making it an explicit choice would add an action with no
    decision in it.
    """
    generate_at: str | None = None
    moves: list[tuple[str, str, int]] = field(default_factory=list)
    resolve: str | None = None


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

    # Uids of cards the Empire has looked at. Card identities never change, so
    # once seen a card stays known.
    empire_known_uids: set[int] = field(default_factory=set)

    # Human-readable record of what happened, for notebooks and debugging.
    log: list[str] = field(default_factory=list)

    def clone(self) -> "GameState":
        return GameState(
            scenario=self.scenario,
            towns={
                tid: Town(
                    id=t.id, label=t.label, neighbors=t.neighbors,
                    pile=list(t.pile), troops=t.troops, resolved=t.resolved,
                    winner=t.winner, resolved_influence=t.resolved_influence,
                    resolved_strength=t.resolved_strength,
                )
                for tid, t in self.towns.items()
            },
            deck=list(self.deck),
            hand=list(self.hand),
            scores=dict(self.scores),
            to_move=self.to_move,
            round_number=self.round_number,
            game_over=self.game_over,
            empire_known_uids=set(self.empire_known_uids),
            log=list(self.log),
        )

    # -- convenience views -------------------------------------------------

    @property
    def unresolved(self) -> list[Town]:
        return [t for t in self.towns.values() if not t.resolved]

    @property
    def resolved(self) -> list[Town]:
        return [t for t in self.towns.values() if t.resolved]

    def influence_in(self, town_id: str) -> int:
        """True total influence in a pile. Omniscient: the Empire cannot see this."""
        return sum(c.influence for c in self.towns[town_id].pile)

    def strength_in(self, town_id: str) -> int:
        return self.towns[town_id].troops * self.scenario.unit.strength

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
                             influence=card_type.influence))
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

    influence = sum(c.influence for c in town.pile)
    strength = town.troops * state.scenario.unit.strength

    if strength > influence:
        winner = Side.EMPIRE
    elif influence > strength:
        winner = Side.INSURGENCY
    else:
        winner = Side.EMPIRE if state.scenario.empire_wins_ties else Side.INSURGENCY

    # You score only what you take off the opponent.
    if winner is Side.EMPIRE:
        state.scores[Side.EMPIRE] += influence
    else:
        state.scores[Side.INSURGENCY] += strength

    town.resolved = True
    town.winner = winner
    town.resolved_influence = influence
    town.resolved_strength = strength

    # Everything committed here is spent. The pile stays face up as a public
    # record of the fight; the troops are removed from play entirely.
    if state.scenario.consume_troops:
        town.troops = 0

    # The flip is public, so both players now know these cards.
    state.empire_known_uids.update(c.uid for c in town.pile)

    who = "auto" if declared_by is None else declared_by.value
    state.log.append(
        f"R{state.round_number}: {town.label} resolved ({who}) — "
        f"influence {influence} vs strength {strength} — {winner.value} takes it, "
        f"scoring {influence if winner is Side.EMPIRE else strength}"
    )
    return winner


def legal_generation_towns(state: GameState) -> list[str]:
    """Where the Empire may raise troops.

    Normally: any town it already stands in. Resolved towns do not qualify —
    the garrison there was spent, so the Empire no longer holds the place.

    The fallback exists because troops are consumed by resolution: an Empire
    that commits its last troops would otherwise have no legal generation site
    and be eliminated with turns still on the clock. With nothing on the board
    it may raise troops anywhere still contested.
    """
    held = [t.id for t in state.towns.values() if t.has_empire_presence]
    return held if held else [t.id for t in state.unresolved]


def _can_declare(state: GameState, town_id: str, side: Side) -> bool:
    """Presence requirement (Decision 5)."""
    if town_id not in state.towns:
        return False
    town = state.towns[town_id]
    if town.resolved:
        return False
    if side is Side.EMPIRE:
        return town.troops > 0
    return len(town.pile) > 0


def legal_resolutions(state: GameState, side: Side) -> list[str]:
    return [t.id for t in state.unresolved if _can_declare(state, t.id, side)]


# ---------------------------------------------------------------------------
# Turn application
# ---------------------------------------------------------------------------

def apply_insurgency_turn(state: GameState, turn: InsurgencyTurn) -> None:
    if state.to_move is not Side.INSURGENCY:
        raise IllegalMove("not the Insurgency's turn")

    # The whole hand must be placed (Decision 6).
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

    if turn.resolve is not None:
        if not _can_declare(state, turn.resolve, Side.INSURGENCY):
            raise IllegalMove(
                f"Insurgency cannot declare {turn.resolve}: no cards there, "
                f"or already resolved"
            )
        resolve_town(state, turn.resolve, Side.INSURGENCY)

    state.to_move = Side.EMPIRE


def apply_empire_turn(state: GameState, turn: EmpireTurn) -> None:
    if state.to_move is not Side.EMPIRE:
        raise IllegalMove("not the Empire's turn")

    scenario = state.scenario

    # 1. Generate. Requires existing presence, which frozen troops satisfy.
    if turn.generate_at is not None:
        town = state.towns.get(turn.generate_at)
        if town is None:
            raise IllegalMove(f"unknown town {turn.generate_at!r}")
        if turn.generate_at not in legal_generation_towns(state):
            raise IllegalMove(
                f"cannot generate at {turn.generate_at}: no Empire presence"
            )
        town.troops += scenario.generation_rate
        state.log.append(
            f"R{state.round_number}: Empire raises "
            f"{scenario.generation_rate} at {town.label}"
        )

    # 2. Move. Record departures first so we can work out who stayed still.
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

    # 3. Look. Every troop that did not move peeks; peeks stack per town.
    for town in state.towns.values():
        if town.resolved or not town.pile:
            continue
        # town.troops already reflects both departures and arrivals, so the
        # troops that held still are whatever is left once arrivals are removed.
        # A troop generated this turn counts as stationary: it did not move.
        arrived = sum(q for _, d, q in turn.moves if d == town.id)
        stationary = town.troops - arrived
        if stationary <= 0:
            continue
        _peek(state, town, stationary * scenario.unit.peek)

    # 4. Optionally resolve.
    if turn.resolve is not None:
        if not _can_declare(state, turn.resolve, Side.EMPIRE):
            raise IllegalMove(
                f"Empire cannot declare {turn.resolve}: no troops there, "
                f"or already resolved"
            )
        resolve_town(state, turn.resolve, Side.EMPIRE)

    state.to_move = Side.INSURGENCY
    state.round_number += 1


def _peek(state: GameState, town: Town, look_count: int) -> None:
    """Draw from the top, look, return to the bottom (Decision 8).

    Looking at more cards than the pile holds is pointless — after a full
    cycle you are re-reading cards you just saw — so it is capped.
    """
    for _ in range(min(look_count, len(town.pile))):
        card = town.pile.pop(0)
        state.empire_known_uids.add(card.uid)
        town.pile.append(card)


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


def _end_game(state: GameState, reason: str) -> None:
    state.log.append(f"R{state.round_number}: game ends — {reason}")
    for town in list(state.unresolved):
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
