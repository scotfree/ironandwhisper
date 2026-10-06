"""The game's table statistics, counted in the simulator exactly as the game counts them.

`stats.jsonc` is the list; `modules/php/Stats.php` records it on BGA; this
records the same names at the same five moments — setup, a resolution, a
placement, the end of an Empire turn, and the end of the game — into
`GameState.stats`. That is what lets `python -m sim.run` print numbers that sit
beside the ones BGA collects from real games: a bot-against-bot distribution
and a production one, under one set of definitions.

Change a definition here and in Stats.php together, and in stats.jsonc.
`sim/test_stats.py` and `tests/test_stats.php` hold all three to one list.
"""

from __future__ import annotations

import json
import re
from pathlib import Path

STATS_FILE = Path(__file__).resolve().parent.parent / "stats.jsonc"

END_DECK = "deck"
END_BOARD = "board"
END_ELIMINATION = "elimination"
END_AGREEMENT = "agreement"


def definitions() -> dict[str, dict]:
    """The table statistics stats.jsonc defines, by name."""
    text = re.sub(r"^\s*//.*$", "", STATS_FILE.read_text(), flags=re.M)
    return json.loads(text)["table"]


_DEFAULTS = {"int": 0, "float": 0.0, "bool": False}


def setup(state, empire_bot: int = 0, rebels_bot: int = 0) -> None:
    """Every statistic at its starting value. Bots: 0 a person, else option value + 1."""
    state.stats = {name: _DEFAULTS[d["type"]] for name, d in definitions().items()}
    state.stats["empire_bot"] = empire_bot
    state.stats["rebels_bot"] = rebels_bot
    state.stats["location_value"] = bool(state.scenario.location_value)


def _inc(state, name: str, delta) -> None:
    # A planning copy of the board (GameState.clone) carries no statistics;
    # bots resolve towns on those, and nothing they do there should count.
    if state.stats:
        state.stats[name] += delta


def _set(state, name: str, value) -> None:
    if state.stats:
        state.stats[name] = value


def resolution(state, declared_by, winner, card_presence: int, troop_presence: int,
               points: int, troops_lost: int, cards, location_points: int = 0) -> None:
    """One location resolved, declared or in the end-of-game sweep.

    `points` is the presence taken off the loser; `location_points` is the
    town's own value, nonzero only when the table scores locations.
    """
    from .engine import Side  # the engine imports this module

    if declared_by is Side.EMPIRE:
        _inc(state, "empire_resolutions", 1)
    elif declared_by is Side.INSURGENCY:
        _inc(state, "rebels_resolutions", 1)
    if card_presence > 0 and troop_presence > 0 and state.stats and state.stats["first_battle_round"] == 0:
        _set(state, "first_battle_round", state.round_number)

    if winner is Side.EMPIRE:
        _inc(state, "empire_locations", 1)
        _inc(state, "empire_points_locations", location_points)
        if points == 0:
            _inc(state, "empire_zero_wins", 1)
        if card_presence > 0 and card_presence == troop_presence:
            _inc(state, "empire_tie_wins", 1)
        _inc(state, "bluffs_captured", sum(1 for c in cards if c.presence == 0))
        return

    _inc(state, "rebels_locations", 1)
    _inc(state, "rebels_points_locations", location_points)
    if points == 0:
        _inc(state, "rebels_zero_wins", 1)
    _inc(state, "rebels_points_battle", points)
    _inc(state, "troops_lost_battle", troops_lost)
    if troops_lost == 1:
        _inc(state, "lone_garrisons_lost", 1)


def placement(state, cards) -> None:
    _inc(state, "agents_placed", len(cards))
    _inc(state, "bluffs_placed", sum(1 for c in cards if c.presence == 0))


def empire_turn(state, built: int, looked: int, starved: int, under_notice: bool) -> None:
    """The Empire's turn is over: the board as attrition left it."""
    if not state.stats:
        return
    _inc(state, "troops_built", built)
    _inc(state, "cards_looked", looked)
    if starved > 0:
        _inc(state, "troops_starved", starved)
        _inc(state, "rebels_points_starvation", starved * state.scenario.unit.presence)
        _inc(state, "starvation_losses", 1)
    if under_notice:
        _inc(state, "starvation_warnings", 1)

    garrisons = [t.troops for t in state.towns.values() if t.troops > 0]
    _inc(state, "empire_turns", 1)
    _inc(state, "empire_garrisons_total", len(garrisons))
    _set(state, "largest_garrison", max([state.stats["largest_garrison"], *garrisons]))
    _set(state, "empire_garrisons_mean",
         round(state.stats["empire_garrisons_total"] / state.stats["empire_turns"], 3))


def game_ended(state, reason: str) -> None:
    from .engine import Side

    if not state.stats:
        return
    empire, rebels = state.scores[Side.EMPIRE], state.scores[Side.INSURGENCY]
    _set(state, f"ended_by_{reason}", True)
    _set(state, "rounds_played", state.round_number)
    _set(state, "empire_score", empire)
    _set(state, "rebels_score", rebels)
    _set(state, "open_locations", sum(1 for t in state.towns.values() if not t.resolved))

    # The two shares sim.run has always printed, per game rather than per batch.
    troop_presence = state.scenario.total_troop_presence
    card_presence = state.scenario.total_card_presence
    _set(state, "empire_presence_overcome_pct",
         round(100 * rebels / troop_presence, 2) if troop_presence else 0.0)
    _set(state, "rebel_presence_captured_pct",
         round(100 * empire / card_presence, 2) if card_presence else 0.0)
