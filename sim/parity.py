"""Record GlobEmpire's decision on a set of random boards, for the PHP to match.

    sim/.venv/bin/python -m sim.parity            # rewrite the fixture
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

Regenerate after any deliberate change to GlobEmpire, and expect the PHP to be
what moves — `sim/bots.py` is the specification.
"""

from __future__ import annotations

import argparse
import json
import random
from pathlib import Path

from .bots import GlobEmpire
from .config import load_scenario
from .engine import Card, Side, new_game

FIXTURE = Path(__file__).resolve().parent.parent / "tests" / "fixtures" / "glob_parity.jsonl"


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
            town.pile = [Card(uid=1000 + j, type_id=f"influence{v}", influence=v)
                         for j, v in enumerate(pile)]
            town.revealed = [Card(uid=2000 + j, type_id=f"influence{v}", influence=v)
                             for j, v in enumerate(revealed)]
            position[town_id] = {
                "troops": town.troops,
                "resolved": town.resolved,
                "winner": None if town.winner is None else town.winner.value,
                "pile": pile,
                "revealed": revealed,
            }
        yield state, position


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--scenario", default="baseline")
    parser.add_argument("--count", type=int, default=150)
    parser.add_argument("--out", type=Path, default=FIXTURE)
    args = parser.parse_args()

    scenario = load_scenario(args.scenario)
    lines = []
    for state, position in positions(scenario, args.count):
        plan = GlobEmpire(random.Random(0)).choose(state)
        lines.append(json.dumps({
            "scenario": args.scenario,
            "position": position,
            "decision": {
                "resolve": plan.resolve,
                "produce": dict(sorted(plan.produce.items())),
                "moves": [list(move) for move in plan.moves],
                "disband": dict(sorted(plan.disband.items())),
            },
        }, separators=(",", ":")))

    args.out.parent.mkdir(parents=True, exist_ok=True)
    # One board per line: the diff of a regenerated fixture is then readable.
    args.out.write_text("\n".join(lines) + "\n")
    print(f"wrote {len(lines)} positions to {args.out}")


if __name__ == "__main__":
    main()
