"""Record GlobEmpire's decision on a set of random boards, for the PHP to match.

    sim/.venv/bin/python -m sim.parity            # rewrite GlobEmpire's fixture
    sim/.venv/bin/python -m sim.parity --bot mist # and MistBot's
    sim/.venv/bin/python -m sim.parity --count 300

`tests/selfplay.php` compares the two engines statistically, which catches drift
but not subtlety: scores are small integers and a fifth of games are draws, so
win rate is a noisy instrument and a real difference can hide inside it for a
thousand games. This is the sharp version of the same question. Every board here
is run through both bots and every field of the decision — resolve, produce,
moves, disband — has to match exactly.

Boards are generated rather than played, so they include positions a game would
rarely reach: networks already over their ceiling, towns the rebels have taken,
garrisons stranded beside them. Those are where a port drifts.

Regenerate after any deliberate change to either bot, and expect the PHP to be
what moves — `sim/bots.py` is the specification.

MistBot's positions carry a hand as well as a board, since a rebel decision is
a function of both. Hand cards are numbered from `HAND_UID` so the PHP can map
a card id back to the index the simulator recorded.
"""

from __future__ import annotations

import argparse
import json
import random
from pathlib import Path

from .bots import GlobEmpire, MistBot
from .config import load_scenario
from .engine import Card, Side, new_game

FIXTURES = Path(__file__).resolve().parent.parent / "tests" / "fixtures"
FIXTURE = FIXTURES / "glob_parity.jsonl"
MIST_FIXTURE = FIXTURES / "mist_parity.jsonl"

# Hand card ids the PHP fixture reader mirrors: card `HAND_UID + i` is hand
# index i, which is how a placement of ids becomes a placement of indices.
HAND_UID = 500


def positions(scenario, count: int, seed: int = 7):
    """Random Empire positions on the scenario's map, as plain JSON-able dicts."""
    rng = random.Random(seed)
    for i in range(count):
        state = new_game(scenario, random.Random(i))
        position = {}
        for town_id, town in state.towns.items():
            town.troops = rng.choice([0, 0, 1, 1, 2, 3, 4])
            town.resolved = rng.random() < 0.25
            town.winner = None
            if town.resolved:
                town.winner = rng.choice([Side.EMPIRE, Side.INSURGENCY])
                if town.winner is Side.INSURGENCY:
                    town.troops = 0  # the rebels took it; nothing of ours stands
            pile = [] if town.resolved else [rng.choice([0, 1]) for _ in range(rng.randint(0, 5))]
            revealed = [] if town.resolved else [rng.choice([0, 1]) for _ in range(rng.randint(0, 2))]
            town.pile = [Card(uid=1000 + j, type_id=f"presence{v}", presence=v)
                         for j, v in enumerate(pile)]
            town.revealed = [Card(uid=2000 + j, type_id=f"presence{v}", presence=v)
                             for j, v in enumerate(revealed)]
            position[town_id] = {
                "troops": town.troops,
                "resolved": town.resolved,
                "winner": None if town.winner is None else town.winner.value,
                "pile": pile,
                "revealed": revealed,
            }
        yield state, position


def deal(scenario, rng: random.Random) -> list[Card]:
    """A hand of the scenario's size, drawn from the values its deck holds."""
    values = sorted({t.presence for t in scenario.card_types.values()})
    return [
        Card(uid=HAND_UID + i, type_id=f"presence{v}", presence=v)
        for i, v in enumerate(rng.choice(values) for _ in range(scenario.hand_size))
    ]


def glob_decision(state, _rng) -> dict:
    plan = GlobEmpire(random.Random(0)).choose(state)
    return {
        "resolve": plan.resolve,
        "produce": dict(sorted(plan.produce.items())),
        "moves": [list(move) for move in plan.moves],
        "disband": dict(sorted(plan.disband.items())),
    }


def mist_decision(state, rng) -> dict:
    """The rebels see everything they placed, so the hand is part of the board."""
    state.hand = deal(state.scenario, rng)
    state.to_move = Side.INSURGENCY
    plan = MistBot(random.Random(0)).choose(state)
    return {
        "hand": [c.presence for c in state.hand],
        "resolve": plan.resolve,
        "placements": {tid: list(ix) for tid, ix in sorted(plan.placements.items())},
    }


BOTS = {
    "glob": (glob_decision, FIXTURE),
    "mist": (mist_decision, MIST_FIXTURE),
}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--scenario", default="baseline")
    parser.add_argument("--bot", choices=sorted(BOTS), default="glob")
    parser.add_argument("--count", type=int, default=150)
    parser.add_argument("--out", type=Path, default=None)
    args = parser.parse_args()

    decide, default_out = BOTS[args.bot]
    args.out = args.out or default_out

    scenario = load_scenario(args.scenario)
    rng = random.Random(11)
    lines = []
    for state, position in positions(scenario, args.count):
        decision = decide(state, rng)
        record = {"scenario": args.scenario, "position": position}
        if "hand" in decision:
            record["hand"] = decision.pop("hand")
        record["decision"] = decision
        lines.append(json.dumps(record, separators=(",", ":")))

    args.out.parent.mkdir(parents=True, exist_ok=True)
    # One board per line: the diff of a regenerated fixture is then readable.
    args.out.write_text("\n".join(lines) + "\n")
    print(f"wrote {len(lines)} positions to {args.out}")


if __name__ == "__main__":
    main()
