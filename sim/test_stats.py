"""Tests for the table statistics the simulator counts the way BGA does.

`stats.jsonc` is the list, `sim/stats.py` counts it here and
`modules/php/Stats.php` counts it on BGA. These pin the simulator's half: every
name is counted, and whole games satisfy the identities the definitions imply.
tests/test_stats.php pins the PHP half against the same identities.

Run with:  sim/.venv/bin/python -m pytest sim -q
"""

from __future__ import annotations

import random

import pytest

from . import stats
from .bots import HeuristicInsurgency, ImperialMonolithBot, Mist2Insurgency, GlobEmpire
from .config import load_scenario
from .engine import Side, new_game, play_game


def test_a_new_game_counts_every_statistic_stats_jsonc_defines():
    state = new_game(load_scenario("baseline"), random.Random(0))
    assert set(state.stats) == set(stats.definitions())


def test_a_planning_copy_counts_nothing():
    state = new_game(load_scenario("baseline"), random.Random(0))
    assert state.clone().stats == {}


GAMES = [(empire, rebels, seed)
         for empire in (ImperialMonolithBot, GlobEmpire)
         for rebels in (Mist2Insurgency, HeuristicInsurgency)
         for seed in range(5)]


@pytest.mark.parametrize("empire,rebels,seed", GAMES)
def test_whole_games_satisfy_the_identities_the_definitions_imply(empire, rebels, seed):
    scenario = load_scenario("baseline")
    state = play_game(scenario, empire(), rebels(random.Random(seed)), random.Random(seed))
    s = state.stats

    assert s["empire_score"] == state.scores[Side.EMPIRE]
    assert s["rebels_score"] == state.scores[Side.INSURGENCY]
    assert s["rebels_score"] == s["rebels_points_battle"] + s["rebels_points_starvation"]
    assert s["troops_starved"] * scenario.unit.presence == s["rebels_points_starvation"]
    assert s["troops_lost_battle"] * scenario.unit.presence == s["rebels_points_battle"]
    assert s["empire_locations"] + s["rebels_locations"] + s["open_locations"] == len(state.towns)
    assert s["empire_resolutions"] + s["rebels_resolutions"] <= s["empire_locations"] + s["rebels_locations"]
    assert sum(s[f"ended_by_{code}"] for code in ("deck", "board", "elimination", "agreement")) == 1
    assert s["bluffs_placed"] <= s["agents_placed"] <= scenario.deck_size
    assert s["empire_tie_wins"] <= s["empire_locations"]
    assert s["lone_garrisons_lost"] <= s["rebels_locations"]
    assert s["starvation_losses"] <= s["starvation_warnings"]
    assert s["rounds_played"] == state.round_number
    if s["empire_turns"]:
        assert s["empire_garrisons_mean"] == round(s["empire_garrisons_total"] / s["empire_turns"], 3)
