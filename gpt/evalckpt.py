"""Evaluate saved checkpoints against one Empire bot, properly.

    sim/.venv/bin/python -m gpt.evalckpt runs/overnight/clone.json runs/overnight/best.json

The in-run evaluation is deliberately small and averaged over both Empire bots,
which flatters a policy that has learned to farm the weak one. This is the
honest measurement: one opponent at a time, more games, both sampled and
greedy.
"""

from __future__ import annotations

import argparse
import json
import multiprocessing as mp
from pathlib import Path

from .evaluate import eval_task, summarise
from .train import worker_init

BOTS = ("GlobEmpire", "HeuristicEmpire")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("checkpoints", nargs="+")
    parser.add_argument("--games", type=int, default=200)
    parser.add_argument("--workers", type=int, default=2)
    parser.add_argument("--scenario", default="baseline")
    args = parser.parse_args()

    first = json.loads(Path(args.checkpoints[0]).read_text())
    model = first["model"]

    mp.set_start_method("spawn", force=True)
    rows = {}
    with mp.Pool(args.workers, initializer=worker_init,
                 initargs=(args.scenario, model, BOTS)) as pool:
        for path in args.checkpoints:
            weights = json.loads(Path(path).read_text())["weights"]
            name = Path(path).parent.name + "/" + Path(path).stem
            rows[name] = {}
            for bot_index, bot in enumerate(BOTS):
                for greedy in (True, False):
                    chunks = [
                        (weights, 4409 + c, args.games // args.workers, greedy, bot_index)
                        for c in range(args.workers)
                    ]
                    margins = [m for result in pool.map(eval_task, chunks) for m in result]
                    label = f"{'greedy' if greedy else 'sampled'}/{bot}"
                    rows[name][label] = summarise(margins)
                    print(f"{name:28s} {label:26s} "
                          f"margin {rows[name][label]['margin']:+6.2f} "
                          f"wins {rows[name][label]['wins']:.0%}", flush=True)

    Path("gpt/runs/checkpoints.json").write_text(json.dumps(rows, indent=1))


if __name__ == "__main__":
    main()
