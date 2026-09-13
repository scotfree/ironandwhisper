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

from .bots import EMPIRE_BOTS, INSURGENCY_BOTS
from .config import Scenario, load_scenario
from .engine import Side, play_game, winner

# `--bots X` is shorthand for "X on both sides where X exists". The sides are
# named separately as well, because the interesting comparisons are mixed: a new
# Empire bot is only worth anything measured against the same Insurgency.
def bot_pair(empire: str, insurgency: str):
    return EMPIRE_BOTS[empire], INSURGENCY_BOTS[insurgency]


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
    presence_captured: int
    presence_overcome: int


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
        presence_captured=state.scores[Side.EMPIRE],
        presence_overcome=state.scores[Side.INSURGENCY],
    )


def run_many(scenario: Scenario, games: int = 200, bots: str = "heuristic",
             base_seed: int = 0, insurgency: str | None = None) -> list[Result]:
    empire_bot, insurgency_bot = bot_pair(bots, insurgency or bots)
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
    empire_engagement = statistics.mean(insurgency_scores) / scenario.total_troop_presence
    insurgency_engagement = (
        statistics.mean(empire_scores) / scenario.total_card_presence
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
        f"  of all Empire presence, {empire_engagement:.1%} was overcome and scored",
        f"  of all rebel presence, {insurgency_engagement:.1%} was captured "
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
    parser.add_argument("--bots", choices=sorted(EMPIRE_BOTS), default="heuristic",
                        help="Empire bot, and the Insurgency bot too unless "
                             "--insurgency says otherwise")
    parser.add_argument("--insurgency", choices=sorted(INSURGENCY_BOTS), default=None)
    parser.add_argument("--seed", type=int, default=0)
    parser.add_argument("--set", action="append", default=[], metavar="KEY=VALUE",
                        help="override a scenario field, e.g. --set generation_rate=2")
    parser.add_argument("--csv", help="write per-game results to this file")
    args = parser.parse_args()

    overrides = dict(parse_override(s) for s in args.set)
    scenario = load_scenario(args.scenario, **overrides)
    insurgency = args.insurgency or (
        args.bots if args.bots in INSURGENCY_BOTS else "heuristic"
    )
    results = run_many(scenario, args.games, args.bots, args.seed, insurgency)

    print(f"bots: Empire {args.bots} vs Insurgency {insurgency}")
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
