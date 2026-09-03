"""Batch runner: play many games and report the outcome distribution.

    sim/.venv/bin/python -m sim.run --games 500
    sim/.venv/bin/python -m sim.run --games 500 --set generation_rate=2
    sim/.venv/bin/python -m sim.run --games 500 --csv /tmp/results.csv
"""

from __future__ import annotations

import argparse
import csv
import random
import statistics
from dataclasses import dataclass

from .bots import (
    HeuristicEmpire,
    HeuristicInsurgency,
    RandomEmpire,
    RandomInsurgency,
)
from .config import Scenario, load_scenario
from .engine import Side, play_game, winner

BOT_CHOICES = {
    "random": (RandomEmpire, RandomInsurgency),
    "heuristic": (HeuristicEmpire, HeuristicInsurgency),
}


@dataclass
class Result:
    seed: int
    empire_score: int
    insurgency_score: int
    winner: str
    rounds: int
    towns_to_empire: int
    towns_to_insurgency: int
    # How much of each side's total force actually got committed to a fight
    # that scored. Low numbers mean the game is mostly walkovers.
    influence_captured: int
    strength_overcome: int


def run_one(scenario: Scenario, empire_bot, insurgency_bot, seed: int) -> Result:
    rng = random.Random(seed)
    state = play_game(scenario, empire_bot(rng), insurgency_bot(rng), rng)
    result = winner(state)
    return Result(
        seed=seed,
        empire_score=state.scores[Side.EMPIRE],
        insurgency_score=state.scores[Side.INSURGENCY],
        winner="draw" if result is None else result.value,
        rounds=state.round_number,
        towns_to_empire=sum(
            1 for t in state.towns.values() if t.winner is Side.EMPIRE
        ),
        towns_to_insurgency=sum(
            1 for t in state.towns.values() if t.winner is Side.INSURGENCY
        ),
        influence_captured=state.scores[Side.EMPIRE],
        strength_overcome=state.scores[Side.INSURGENCY],
    )


def run_many(scenario: Scenario, games: int = 200, bots: str = "heuristic",
             base_seed: int = 0) -> list[Result]:
    empire_bot, insurgency_bot = BOT_CHOICES[bots]
    return [
        run_one(scenario, empire_bot, insurgency_bot, base_seed + i)
        for i in range(games)
    ]


def summarise(results: list[Result], scenario: Scenario) -> str:
    total = len(results)
    empire_wins = sum(1 for r in results if r.winner == "empire")
    insurgency_wins = sum(1 for r in results if r.winner == "insurgency")
    draws = total - empire_wins - insurgency_wins

    empire_scores = [r.empire_score for r in results]
    insurgency_scores = [r.insurgency_score for r in results]

    # What fraction of each side's whole budget ever changed hands? If this is
    # near zero the game is mostly walkovers, which is a design problem.
    empire_engagement = statistics.mean(insurgency_scores) / scenario.total_strength
    insurgency_engagement = (
        statistics.mean(empire_scores) / scenario.total_influence
    )

    return "\n".join([
        scenario.summary(),
        "",
        f"  games             {total}",
        f"  Empire wins       {empire_wins:>4}  ({empire_wins / total:.1%})",
        f"  Insurgency wins   {insurgency_wins:>4}  ({insurgency_wins / total:.1%})",
        f"  draws             {draws:>4}  ({draws / total:.1%})",
        "",
        f"  Empire score      mean {statistics.mean(empire_scores):5.1f}   "
        f"median {statistics.median(empire_scores):5.1f}",
        f"  Insurgency score  mean {statistics.mean(insurgency_scores):5.1f}   "
        f"median {statistics.median(insurgency_scores):5.1f}",
        "",
        f"  of all Empire strength, {empire_engagement:.1%} was overcome and scored",
        f"  of all Insurgency influence, {insurgency_engagement:.1%} was captured "
        f"and scored",
    ])


def parse_override(text: str):
    key, _, raw = text.partition("=")
    try:
        value = int(raw)
    except ValueError:
        value = {"true": True, "false": False}.get(raw.lower(), raw)
    return key, value


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--scenario", default="baseline")
    parser.add_argument("--games", type=int, default=200)
    parser.add_argument("--bots", choices=sorted(BOT_CHOICES), default="heuristic")
    parser.add_argument("--seed", type=int, default=0)
    parser.add_argument("--set", action="append", default=[], metavar="KEY=VALUE",
                        help="override a scenario field, e.g. --set generation_rate=2")
    parser.add_argument("--csv", help="write per-game results to this file")
    args = parser.parse_args()

    overrides = dict(parse_override(s) for s in args.set)
    scenario = load_scenario(args.scenario, **overrides)
    results = run_many(scenario, args.games, args.bots, args.seed)

    print(f"bots: {args.bots}")
    print(summarise(results, scenario))

    if args.csv:
        with open(args.csv, "w", newline="") as f:
            writer = csv.DictWriter(f, fieldnames=list(vars(results[0])))
            writer.writeheader()
            for r in results:
                writer.writerow(vars(r))
        print(f"\nwrote {len(results)} rows to {args.csv}")


if __name__ == "__main__":
    main()
