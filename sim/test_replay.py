"""Tests for replaying real games from their BGA logs.

Every log in logs/ must replay: the simulator has to reach the same
resolutions, the same troops after every Empire turn, the same starvation marks
and the same final score as the server did. And on every turn a bot played, the
simulator's copy of that bot has to choose exactly what the PHP one chose —
parity on positions people actually reached, rather than on generated boards.

A new log that fails here is a finding, not a flaky test: the parser, the engine
or the port disagrees with the game that was played.

Run with:  sim/.venv/bin/python -m pytest sim -q
"""

from __future__ import annotations

import copy
import json
from pathlib import Path

import pytest

from .engine import Side
from .replay import ReplayMismatch, bot_turn, load, replay, same

LOGS = sorted((Path(__file__).resolve().parent.parent / 'logs').glob('*.json'))


@pytest.mark.parametrize('path', LOGS, ids=lambda p: p.stem)
def test_every_log_replays_to_the_score_the_server_gave(path):
    result = load(path)
    assert result.final.game_over
    assert result.final.scores == result.logged_scores


@pytest.mark.parametrize('path', LOGS, ids=lambda p: p.stem)
def test_a_bot_turn_in_a_real_game_is_what_the_simulator_bot_would_play(path):
    result = load(path)
    bot_turns = [d for d in result.decisions if d.by_bot]
    assert bot_turns, 'every logged game so far has a bot in it'
    for decision in bot_turns:
        assert same(decision, decision.turn, bot_turn(decision)), (
            f'R{decision.round} {decision.side.value}: the PHP bot and the simulator bot disagree')


def test_a_log_that_disagrees_with_the_rules_is_caught():
    # Hand the first contested resolution to the other side. The replay has to
    # notice rather than quietly carry on with its own version of events.
    log = json.loads(LOGS[0].read_text())
    for packet in log['data']['logs']:
        for note in packet['data']:
            if note['type'] == 'townResolved' and note['args'].get('declaredBy'):
                note['args']['winner'] = Side(note['args']['winner']).other.value
                break
        else:
            continue
        break

    with pytest.raises(ReplayMismatch):
        replay(log)


def test_both_sides_of_a_game_are_labelled_by_who_played_them():
    # 975612: a person played the rebels against the Empire bot.
    result = load(next(p for p in LOGS if p.stem == '975612'))
    sides = {(d.side, d.by_bot) for d in result.decisions}
    assert sides == {(Side.INSURGENCY, False), (Side.EMPIRE, True)}
