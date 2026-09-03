"""Human-facing table: render the board and submit turns by hand.

Designed for a notebook or a REPL. Either side can be a bot or a human, so you
can play the Insurgency against a bot Empire, play both sides yourself to walk
through a position, or watch two bots and step in partway.

    from sim.play import Table
    from sim.bots import HeuristicEmpire

    t = Table("baseline", seed=1, empire=HeuristicEmpire())
    t.show()
    t.place("everlan", influence=3, dummy=2)
    t.end_turn()        # your turn is applied, then the bot Empire replies
"""

from __future__ import annotations

import random

from .bots import HeuristicEmpire, HeuristicInsurgency
from .config import load_scenario
from .engine import (
    EmpireTurn,
    GameState,
    IllegalMove,
    InsurgencyTurn,
    Side,
    apply_empire_turn,
    apply_insurgency_turn,
    legal_generation_towns,
    new_game,
    prepare_turn,
    winner,
)


# ---------------------------------------------------------------------------
# Rendering
# ---------------------------------------------------------------------------

def render_board(state: GameState, view: Side | None = None) -> str:
    """Render the board as text.

    view=Side.EMPIRE       only what the Empire may know
    view=Side.INSURGENCY   the Insurgency knows every pile it placed
    view=None              omniscient, for debugging and post-mortems
    """
    lines = []

    if state.game_over:
        result = winner(state)
        headline = "draw" if result is None else f"{result.value} wins"
        lines.append(f"GAME OVER — {headline}")
    else:
        lines.append(f"Round {state.round_number} — {state.to_move.value} to move")

    lines.append(
        f"Empire {state.scores[Side.EMPIRE]}  ·  "
        f"Insurgency {state.scores[Side.INSURGENCY]}    "
        f"deck {len(state.deck)}  hand {len(state.hand)}"
    )
    lines.append("")

    header = f"{'TOWN':<12}{'PILE':>5}{'TROOPS':>8}   {'INTEL':<24}STATUS"
    lines.append(header)
    lines.append("-" * len(header))

    for town in state.towns.values():
        troops = str(town.troops) if town.troops else "·"

        pile_size = town.card_count
        intel = ""

        if town.resolved:
            status = (
                f"resolved · {town.winner.value} · "
                f"inf {town.resolved_influence} v str {town.resolved_strength}"
            )
        else:
            status = ""
            if not pile_size:
                intel = ""
            elif view is Side.INSURGENCY or view is None:
                influence = sum(c.influence for c in town.cards)
                intel = f"influence {influence} of {pile_size}"
            elif view is Side.EMPIRE:
                known = town.revealed
                if known:
                    known_influence = sum(c.influence for c in known)
                    intel = (
                        f"seen {len(known)}/{pile_size}: "
                        f"{known_influence} influence"
                    )
                elif pile_size:
                    intel = "nothing seen"

        lines.append(
            f"{town.label:<12}{pile_size:>5}{troops:>8}   {intel:<24}{status}"
        )

    if state.hand and view is not Side.EMPIRE:
        influence = sum(1 for c in state.hand if c.influence > 0)
        dummies = len(state.hand) - influence
        lines.append("")
        lines.append(f"HAND: {influence} influence, {dummies} dummy")

    return "\n".join(lines)


def render_map(state: GameState) -> str:
    """ASCII map, for maps laid out on an integer grid."""
    towns = state.scenario.map.towns
    if not all(float(t.x).is_integer() and float(t.y).is_integer() for t in towns):
        return "(map has no grid layout; use plot_map in the notebook)"

    by_position = {(int(t.x), int(t.y)): t for t in towns}
    width = max(int(t.x) for t in towns) + 1
    height = max(int(t.y) for t in towns) + 1
    neighbors = state.scenario.map.neighbors

    lines = []
    for y in range(height):
        cells, links = [], []
        for x in range(width):
            town_def = by_position.get((x, y))
            if town_def is None:
                cells.append(" " * 13)
                links.append("   ")
                continue
            town = state.towns[town_def.id]
            mark = "#" if town.resolved else " "
            cells.append(f"{town.label[:8]:<8}{town.card_count:>2}/{town.troops:<1}{mark}")
            east = by_position.get((x + 1, y))
            links.append("---" if east and east.id in neighbors[town_def.id] else "   ")
        lines.append("".join(c + l for c, l in zip(cells, links)).rstrip())

        if y + 1 < height:
            connector = []
            for x in range(width):
                here, below = by_position.get((x, y)), by_position.get((x, y + 1))
                joined = here and below and below.id in neighbors[here.id]
                connector.append(("     |       " if joined else " " * 13) + "   ")
            lines.append("".join(connector).rstrip())

    lines.append("")
    lines.append("cells show  name  pile/troops  (# = resolved)")
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# The table
# ---------------------------------------------------------------------------

class Table:
    """A game you can drive by hand.

    Pass a bot for either side to have it play automatically; pass None to play
    that side yourself.
    """

    def __init__(self, scenario_id: str = "baseline", seed: int | None = None,
                 empire=None, insurgency=None, **scenario_overrides):
        self.rng = random.Random(seed)
        self.scenario = load_scenario(scenario_id, **scenario_overrides)
        self.state = new_game(self.scenario, self.rng)
        self.bots = {Side.EMPIRE: empire, Side.INSURGENCY: insurgency}
        self._pending_insurgency = InsurgencyTurn()
        self._pending_empire = EmpireTurn()
        prepare_turn(self.state)

    # -- display -----------------------------------------------------------

    def show(self, view: Side | None = "auto") -> None:
        """Print the board. By default, from the perspective of whoever is to move."""
        if view == "auto":
            view = None if self.state.game_over else self.state.to_move
        print(render_board(self.state, view))
        pending = self._describe_pending()
        if pending:
            print("\nPENDING THIS TURN:\n" + pending)

    def map(self) -> None:
        print(render_map(self.state))

    def hand(self) -> None:
        if not self.state.hand:
            print("hand is empty")
            return
        for i, card in enumerate(self.state.hand):
            claimed = any(
                i in ix for ix in self._pending_insurgency.placements.values()
            )
            print(f"  [{i}] {card.type_id:<10}"
                  f"{'(placed)' if claimed else ''}")

    def log(self, last: int = 12) -> None:
        for line in self.state.log[-last:]:
            print(line)

    def _describe_pending(self) -> str:
        if self.state.game_over:
            return ""
        parts = []
        if self.state.to_move is Side.INSURGENCY:
            turn = self._pending_insurgency
            for town_id, indices in turn.placements.items():
                influence = sum(
                    1 for i in indices if self.state.hand[i].influence > 0
                )
                dummies = len(indices) - influence
                parts.append(
                    f"  place {influence} influence + {dummies} dummy "
                    f"-> {self.state.towns[town_id].label}"
                )
            if turn.resolve:
                parts.append(f"  resolve {self.state.towns[turn.resolve].label}")
            unplaced = len(self.state.hand) - sum(
                len(ix) for ix in turn.placements.values()
            )
            if unplaced:
                parts.append(f"  ({unplaced} cards still to place)")
        else:
            turn = self._pending_empire
            if turn.generate_at:
                parts.append(f"  generate at {self.state.towns[turn.generate_at].label}")
            for src, dst, quantity in turn.moves:
                parts.append(
                    f"  move {quantity}: {self.state.towns[src].label} "
                    f"-> {self.state.towns[dst].label}"
                )
            if turn.resolve:
                parts.append(f"  resolve {self.state.towns[turn.resolve].label}")
        return "\n".join(parts)

    # -- Insurgency actions -------------------------------------------------

    def place(self, town: str, influence: int = 0, dummy: int = 0,
              cards: int = 0) -> None:
        """Commit cards from hand to a town, applied when you end the turn.

        Specify influence/dummy counts, or `cards=n` for n of whatever is left.
        """
        self._require(Side.INSURGENCY)
        town_id = self._town_id(town)
        if self.state.towns[town_id].resolved:
            raise IllegalMove(f"{town} is resolved")

        already = {
            i for ix in self._pending_insurgency.placements.values() for i in ix
        }
        available = [i for i in range(len(self.state.hand)) if i not in already]

        chosen = []
        for want, predicate in (
            (influence, lambda c: c.influence > 0),
            (dummy, lambda c: c.influence == 0),
            (cards, lambda c: True),
        ):
            for _ in range(want):
                match = next(
                    (i for i in available
                     if i not in chosen and predicate(self.state.hand[i])), None
                )
                if match is None:
                    raise IllegalMove(
                        "not enough matching cards left in hand — try .hand()"
                    )
                chosen.append(match)

        self._pending_insurgency.placements.setdefault(town_id, []).extend(chosen)

    # -- Empire actions -----------------------------------------------------

    def generate(self, town: str) -> None:
        self._require(Side.EMPIRE)
        town_id = self._town_id(town)
        if town_id not in legal_generation_towns(self.state):
            raise IllegalMove(f"no Empire presence at {town}")
        self._pending_empire.generate_at = town_id

    def move(self, src: str, dst: str, count: int = 1) -> None:
        self._require(Side.EMPIRE)
        self._pending_empire.moves.append(
            (self._town_id(src), self._town_id(dst), count)
        )

    # -- either side --------------------------------------------------------

    def resolve(self, town: str) -> None:
        town_id = self._town_id(town)
        if self.state.to_move is Side.INSURGENCY:
            self._pending_insurgency.resolve = town_id
        else:
            self._pending_empire.resolve = town_id

    def reset_turn(self) -> None:
        """Discard everything staged this turn."""
        self._pending_insurgency = InsurgencyTurn()
        self._pending_empire = EmpireTurn()

    def end_turn(self, then_autoplay: bool = True) -> None:
        """Apply the staged turn, then let any bot opponents take theirs."""
        if self.state.game_over:
            print("game is over")
            return

        if self.state.to_move is Side.INSURGENCY:
            apply_insurgency_turn(self.state, self._pending_insurgency)
        else:
            apply_empire_turn(self.state, self._pending_empire)
        self.reset_turn()
        prepare_turn(self.state)

        if then_autoplay:
            self.autoplay()
        self.show()

    def autoplay(self) -> None:
        """Let bots play until it is a human's turn again, or the game ends."""
        while not self.state.game_over:
            bot = self.bots[self.state.to_move]
            if bot is None:
                return
            turn = bot.choose(self.state)
            if self.state.to_move is Side.EMPIRE:
                apply_empire_turn(self.state, turn)
            else:
                apply_insurgency_turn(self.state, turn)
            prepare_turn(self.state)

    def step(self) -> None:
        """Play exactly one turn using the bot for the side to move."""
        bot = self.bots[self.state.to_move]
        if bot is None:
            raise IllegalMove(
                f"{self.state.to_move.value} has no bot — play the turn yourself"
            )
        turn = bot.choose(self.state)
        if self.state.to_move is Side.EMPIRE:
            apply_empire_turn(self.state, turn)
        else:
            apply_insurgency_turn(self.state, turn)
        prepare_turn(self.state)
        self.show()

    # -- helpers ------------------------------------------------------------

    def _require(self, side: Side) -> None:
        if self.state.to_move is not side:
            raise IllegalMove(
                f"it is the {self.state.to_move.value}'s turn, not the {side.value}'s"
            )

    def _town_id(self, town: str) -> str:
        if town in self.state.towns:
            return town
        matches = [
            t.id for t in self.state.towns.values()
            if t.label.lower().startswith(town.lower())
        ]
        if len(matches) == 1:
            return matches[0]
        if not matches:
            raise IllegalMove(f"no town called {town!r}")
        raise IllegalMove(f"{town!r} is ambiguous: {matches}")
