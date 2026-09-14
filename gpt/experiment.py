"""The driver: clone MistBot, then improve on it with policy gradient.

    sim/.venv/bin/python -m gpt.experiment --name overnight --rl-minutes 120

Cloning first is not a warm-up ritual. It costs a fiftieth of the compute, it
proves the encoding can represent a policy at all before any of the reinforcement
machinery is trusted, and it starts the policy-gradient run from a competent
player rather than from a random one — which is the difference between a
gradient signal and a lottery.

Everything is checkpointed to `runs/<name>/`: the weights after each evaluation,
one JSON line per step, and a summary at the end. The run stops on its time
budget rather than on a step count, so an overnight run is bounded by the clock.
"""

from __future__ import annotations

import argparse
import json
import multiprocessing as mp
import random
import statistics as stats
import time
from pathlib import Path

from sim.config import load_scenario

from .corpus import collect
from .evaluate import evaluate, reference
from .optim import Adam
from .policy import get_weights, new_config, set_weights
from .train import clone_task, reinforce_task, worker_init

RUNS = Path(__file__).resolve().parent / "runs"
EMPIRE_BOTS = ("GlobEmpire", "HeuristicEmpire")


class Log:
    def __init__(self, path: Path):
        self.path = path
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.handle = self.path.open("a")

    def write(self, **record) -> None:
        self.handle.write(json.dumps(record, separators=(",", ":")) + "\n")
        self.handle.flush()


def save_weights(path: Path, weights: list[float], model: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({"model": model, "weights": weights}))


def clone(pool, config, documents, log, args, out_dir) -> list[float]:
    """Teacher-forced cross-entropy on MistBot's turns."""
    weights = get_weights(config)
    adam = Adam(len(weights), lr=args.clone_lr)
    batch = args.clone_batch
    per_worker = max(1, batch // args.workers)
    steps_per_epoch = max(1, len(documents) // batch)
    total = steps_per_epoch * args.clone_epochs
    rng = random.Random(0)

    step = 0
    started = time.time()
    for epoch in range(args.clone_epochs):
        order = list(range(len(documents)))
        rng.shuffle(order)
        for index in range(steps_per_epoch):
            picked = [documents[i] for i in order[index * batch:(index + 1) * batch]]
            slices = [picked[i:i + per_worker] for i in range(0, len(picked), per_worker)]
            results = pool.map(clone_task, [(weights, chunk) for chunk in slices])

            grads = [0.0] * len(weights)
            loss_sum = docs = correct = seen = 0
            for worker_grads, worker_loss, n, worker_correct, worker_steps in results:
                for i, g in enumerate(worker_grads):
                    grads[i] += g
                loss_sum += worker_loss
                docs += n
                correct += worker_correct
                seen += worker_steps
            grads = [g / max(1, docs) for g in grads]

            step += 1
            weights = adam.step(weights, grads, lr_scale=1 - step / total)
            if step % 25 == 0 or step == total:
                log.write(phase="clone", step=step, epoch=epoch,
                          loss=round(loss_sum / max(1, docs), 4),
                          accuracy=round(correct / max(1, seen), 4),
                          elapsed=round(time.time() - started, 1))
                print(f"  clone {step:4d}/{total} loss {loss_sum / max(1, docs):.3f} "
                      f"acc {correct / max(1, seen):.1%}", flush=True)

    save_weights(out_dir / "clone.json", weights, args.model)
    return weights


def reinforce(pool, weights, log, args, out_dir) -> list[float]:
    """Policy gradient, with reward-to-go and a running baseline.

    The baseline is an exponential moving average rather than the mean of the
    current batch: a worker holds one episode's graph at a time and backwards
    it as soon as the game ends, so nothing in the batch is available to
    average over before the gradient is needed. The bias that costs is small
    and the memory it saves is the difference between eight workers and one.
    """
    adam = Adam(len(weights), lr=args.rl_lr)
    baseline, scale = 0.0, 1.0
    deadline = time.time() + args.rl_minutes * 60
    best = (-99.0, weights)
    step = 0
    started = time.time()

    while time.time() < deadline:
        step += 1
        beta = args.entropy * max(0.0, 1 - step / max(1, args.entropy_steps))
        jobs = [
            (weights, step * 1000 + w, args.rl_games, baseline, scale, args.gamma, beta)
            for w in range(args.workers)
        ]
        results = pool.map(reinforce_task, jobs)

        grads = [0.0] * len(weights)
        margins, returns, rounds = [], [], []
        for worker_grads, worker_margins, worker_returns, worker_rounds in results:
            for i, g in enumerate(worker_grads):
                grads[i] += g
            margins.extend(worker_margins)
            returns.extend(worker_returns)
            rounds.extend(worker_rounds)
        episodes = max(1, len(margins))
        grads = [g / episodes for g in grads]

        # running baseline and scale, from the returns actually seen
        if returns:
            mean = stats.mean(returns)
            deviation = stats.pstdev(returns) if len(returns) > 1 else 1.0
            baseline = 0.9 * baseline + 0.1 * mean
            scale = max(0.5, 0.9 * scale + 0.1 * max(deviation, 1e-3))

        weights = adam.step(weights, grads, lr_scale=1.0)

        if step % 10 == 0:
            log.write(phase="rl", step=step, episodes=step * episodes,
                      margin=round(stats.mean(margins), 3),
                      rounds=round(stats.mean(rounds), 2),
                      baseline=round(baseline, 3), scale=round(scale, 3),
                      entropy_beta=round(beta, 4),
                      elapsed=round(time.time() - started, 1))
            print(f"  rl {step:5d} | {step * episodes:6d} games | margin "
                  f"{stats.mean(margins):+6.2f} | rounds {stats.mean(rounds):4.1f} | "
                  f"{(time.time() - started) / 60:5.1f} min", flush=True)

        if step % args.eval_every == 0:
            summary = evaluate(pool, weights, args.eval_games, list(EMPIRE_BOTS), seed=step)
            log.write(phase="eval", step=step, **{k: v for k, v in summary.items()})
            print(f"  eval at {step}: overall {summary['overall']['margin']:+.2f} "
                  f"| greedy/Glob {summary['greedy/GlobEmpire']['margin']:+.2f}", flush=True)
            if summary["overall"]["margin"] > best[0]:
                best = (summary["overall"]["margin"], weights)
                save_weights(out_dir / "best.json", weights, args.model)
        save_weights(out_dir / "latest.json", weights, args.model)

    return best[1] if best[0] > -99 else weights


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--name", default="run")
    parser.add_argument("--scenario", default="baseline")
    parser.add_argument("--workers", type=int, default=8)
    parser.add_argument("--n-embd", type=int, default=8)
    parser.add_argument("--n-head", type=int, default=2)
    parser.add_argument("--corpus-games", type=int, default=4000)
    parser.add_argument("--clone-epochs", type=int, default=6)
    parser.add_argument("--clone-batch", type=int, default=64)
    parser.add_argument("--clone-lr", type=float, default=0.01)
    parser.add_argument("--rl-minutes", type=float, default=60.0)
    parser.add_argument("--rl-games", type=int, default=4, help="episodes per worker per step")
    parser.add_argument("--rl-lr", type=float, default=0.003)
    parser.add_argument("--gamma", type=float, default=1.0)
    parser.add_argument("--entropy", type=float, default=0.02)
    parser.add_argument("--entropy-steps", type=int, default=400)
    parser.add_argument("--eval-every", type=int, default=50)
    parser.add_argument("--eval-games", type=int, default=100)
    parser.add_argument("--skip-clone", action="store_true")
    args = parser.parse_args()

    args.model = dict(n_embd=args.n_embd, n_head=args.n_head, seed=0)
    out_dir = RUNS / args.name
    out_dir.mkdir(parents=True, exist_ok=True)
    log = Log(out_dir / "log.jsonl")

    scenario = load_scenario(args.scenario)
    config = new_config(scenario, **args.model)
    started = time.time()

    print(f"[{args.name}] {len(get_weights(config))} params, vocab {config['vocab_size']}, "
          f"block {config['block_size']}, {args.workers} workers", flush=True)

    mp.set_start_method("spawn", force=True)
    with mp.Pool(args.workers, initializer=worker_init,
                 initargs=(args.scenario, args.model, EMPIRE_BOTS)) as pool:

        weights = get_weights(config)
        log.write(phase="start", params=len(weights), args={
            k: v for k, v in vars(args).items() if k != "model"})

        before = evaluate(pool, weights, args.eval_games, list(EMPIRE_BOTS))
        log.write(phase="eval", step=0, tag="untrained", **before)
        print(f"  untrained: overall {before['overall']['margin']:+.2f}", flush=True)

        if not args.skip_clone:
            print(f"  building corpus from {args.corpus_games} MistBot games...", flush=True)
            documents = collect(scenario, games=args.corpus_games, seed=1)
            print(f"  {len(documents)} documents", flush=True)
            weights = clone(pool, config, documents, log, args, out_dir)
            cloned = evaluate(pool, weights, args.eval_games, list(EMPIRE_BOTS))
            log.write(phase="eval", step=0, tag="cloned", **cloned)
            print(f"  cloned: overall {cloned['overall']['margin']:+.2f} "
                  f"| greedy/Glob {cloned['greedy/GlobEmpire']['margin']:+.2f}", flush=True)

        if args.rl_minutes > 0:
            weights = reinforce(pool, weights, log, args, out_dir)

        final = evaluate(pool, weights, args.eval_games * 2, list(EMPIRE_BOTS), seed=999)
        log.write(phase="eval", step=-1, tag="final", **final)
        save_weights(out_dir / "final.json", weights, args.model)
        print(f"  final: overall {final['overall']['margin']:+.2f}", flush=True)

    log.write(phase="done", minutes=round((time.time() - started) / 60, 2))
    print(f"[{args.name}] done in {(time.time() - started) / 60:.1f} min -> {out_dir}", flush=True)


if __name__ == "__main__":
    main()
