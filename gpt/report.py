"""Turn a run's log into a table a human can read over coffee.

    sim/.venv/bin/python -m gpt.report overnight control > gpt/RESULTS.md
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

RUNS = Path(__file__).resolve().parent / "runs"
KEYS = ("greedy/GlobEmpire", "sampled/GlobEmpire",
        "greedy/HeuristicEmpire", "sampled/HeuristicEmpire", "overall")


def records(name: str) -> list[dict]:
    path = RUNS / name / "log.jsonl"
    if not path.exists():
        return []
    return [json.loads(line) for line in path.read_text().splitlines() if line.strip()]


def evals(rows: list[dict]) -> list[dict]:
    return [r for r in rows if r.get("phase") == "eval"]


def table(name: str) -> str:
    rows = records(name)
    if not rows:
        return f"### {name}\n\n_no log_\n"

    start = next((r for r in rows if r.get("phase") == "start"), {})
    done = next((r for r in rows if r.get("phase") == "done"), {})
    rl = [r for r in rows if r.get("phase") == "rl"]
    clone = [r for r in rows if r.get("phase") == "clone"]

    out = [f"### {name}", ""]
    if start:
        args = start.get("args", {})
        out.append(f"{start.get('params')} parameters · "
                   f"clone {args.get('clone_epochs')} epochs over "
                   f"{args.get('corpus_games')} MistBot games · "
                   f"RL {args.get('rl_minutes')} min at lr {args.get('rl_lr')} · "
                   f"{args.get('workers')} workers")
        out.append("")
    if done:
        out.append(f"Ran {done['minutes']:.0f} minutes.")
        out.append("")

    out.append("| point | " + " | ".join(k.replace("/", " vs ") for k in KEYS) + " |")
    out.append("|---" * (len(KEYS) + 1) + "|")
    for row in evals(rows):
        label = row.get("tag") or f"step {row['step']}"
        cells = []
        for key in KEYS:
            value = row.get(key)
            cells.append(f"{value['margin']:+.2f}" if value else "—")
        out.append(f"| {label} | " + " | ".join(cells) + " |")
    out.append("")

    if clone:
        first, last = clone[0], clone[-1]
        out.append(f"Clone: loss {first['loss']:.3f} → {last['loss']:.3f}, "
                   f"teacher agreement {first['accuracy']:.0%} → {last['accuracy']:.0%} "
                   f"over {last['step']} steps.")
    if rl:
        best = max(rl, key=lambda r: r["margin"])
        out.append(f"RL: {rl[-1]['episodes']} episodes, batch margin "
                   f"{rl[0]['margin']:+.2f} → {rl[-1]['margin']:+.2f} "
                   f"(best batch {best['margin']:+.2f} at step {best['step']}), "
                   f"game length {rl[0]['rounds']:.1f} → {rl[-1]['rounds']:.1f} rounds.")
    out.append("")
    return "\n".join(out)


def checkpoints() -> str:
    """The honest table: one opponent at a time, 200 games each."""
    path = RUNS / "checkpoints.json"
    if not path.exists():
        return ""
    rows = json.loads(path.read_text())
    columns = ["greedy/GlobEmpire", "sampled/GlobEmpire",
               "greedy/HeuristicEmpire", "sampled/HeuristicEmpire"]
    out = ["### Checkpoints, measured per opponent", "",
           "200 games each. `greedy vs GlobEmpire` is the column that matters.", "",
           "| checkpoint | " + " | ".join(c.replace("/", " vs ") for c in columns) + " |",
           "|---" * (len(columns) + 1) + "|"]
    for name, row in rows.items():
        cells = []
        for column in columns:
            cell = row.get(column)
            cells.append(f"{cell['margin']:+.2f} ({cell['wins']:.0%})" if cell else "—")
        out.append(f"| `{name}` | " + " | ".join(cells) + " |")
    out.append("")
    out.append("MistBot for comparison: **+5.57** vs GlobEmpire, +3.80 vs HeuristicEmpire.")
    out.append("")
    return "\n".join(out)


def main() -> None:
    names = sys.argv[1:] or ["overnight"]
    print("# GPT experiment — results\n")
    print("Margin is rebel score minus Empire score. For reference, the hand-written")
    print("bots over 200 games: random −1.85 / heuristic −1.13 / **MistBot +5.57**")
    print("against GlobEmpire, and +3.88 / +6.83 / +3.80 against HeuristicEmpire.")
    print("`greedy vs GlobEmpire` is the honest single number — HeuristicEmpire is weak")
    print("enough that random rebels beat it.\n")
    print(checkpoints())
    for name in names:
        print(table(name))


if __name__ == "__main__":
    main()
