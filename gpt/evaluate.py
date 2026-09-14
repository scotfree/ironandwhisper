"""What the policy is worth, in the same units as every other bot.

Margin is rebel score minus Empire score, which is the quantity the policy is
trained on and the one that separates the hand-written bots cleanly: random
-1.85, heuristic -1.13, MistBot +5.57 against GlobEmpire. Win rate is reported
too but it is the noisier statistic — scores are small integers and draws are
common.

Sampled and greedy are both reported. They can differ a lot: a policy with
high entropy plays better greedily, and one that has collapsed plays the same
either way, which makes the gap a cheap read on whether exploration is dead.
"""

from __future__ import annotations

import random
import statistics as stats

from sim.engine import Side, play_game

from .policy import GptPolicy, set_weights
from .train import _W


def eval_task(job: tuple) -> tuple:
    weights, seed, games, greedy, bot_index = job
    config, vocab, scenario = _W['config'], _W['vocab'], _W['scenario']
    set_weights(config, weights)
    empire_bot = _W['empire'][bot_index]

    margins = []
    for index in range(games):
        rng = random.Random(seed * 7919 + index)
        policy = GptPolicy(config=config, vocab=vocab, hand_size=scenario.hand_size,
                           rng=rng, greedy=greedy)
        state = play_game(scenario, empire_bot(rng), policy, rng)
        margins.append(state.scores[Side.INSURGENCY] - state.scores[Side.EMPIRE])
    return margins


def summarise(margins: list[int]) -> dict:
    return {
        "games": len(margins),
        "margin": round(stats.mean(margins), 3),
        "sd": round(stats.pstdev(margins), 3) if len(margins) > 1 else 0.0,
        "wins": round(sum(m > 0 for m in margins) / len(margins), 4),
        "draws": round(sum(m == 0 for m in margins) / len(margins), 4),
    }


def evaluate(pool, weights, games_per_bot: int, bots: list[str], seed: int = 0) -> dict:
    """Sampled and greedy, against every Empire bot, spread over the pool."""
    jobs = []
    for greedy in (False, True):
        for bot_index in range(len(bots)):
            for chunk in range(4):
                jobs.append((weights, seed + chunk * 131 + bot_index * 17 + int(greedy) * 7919,
                             max(1, games_per_bot // 4), greedy, bot_index))

    results = pool.map(eval_task, jobs)

    out = {}
    for (job, margins) in zip(jobs, results):
        key = f"{'greedy' if job[3] else 'sampled'}/{bots[job[4]]}"
        out.setdefault(key, []).extend(margins)
    summary = {key: summarise(value) for key, value in sorted(out.items())}
    summary["overall"] = summarise([m for value in out.values() for m in value])
    return summary


def reference(scenario, games: int = 200) -> dict:
    """The hand-written bots, measured the same way, for the table."""
    from sim.bots import GlobEmpire, HeuristicEmpire, HeuristicInsurgency, MistBot, RandomInsurgency

    out = {}
    for rebel_name, Rebel in (("random", RandomInsurgency),
                              ("heuristic", HeuristicInsurgency),
                              ("mist", MistBot)):
        for empire_name, Empire in (("GlobEmpire", GlobEmpire),
                                    ("HeuristicEmpire", HeuristicEmpire)):
            margins = []
            for seed in range(games):
                rng = random.Random(seed)
                state = play_game(scenario, Empire(rng), Rebel(rng), rng)
                margins.append(state.scores[Side.INSURGENCY] - state.scores[Side.EMPIRE])
            out[f"{rebel_name}/{empire_name}"] = summarise(margins)
    return out
