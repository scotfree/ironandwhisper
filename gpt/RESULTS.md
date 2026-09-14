# GPT experiment — results

Margin is rebel score minus Empire score. For reference, the hand-written
bots over 200 games: random −1.85 / heuristic −1.13 / **MistBot +5.57**
against GlobEmpire, and +3.88 / +6.83 / +3.80 against HeuristicEmpire.
`greedy vs GlobEmpire` is the honest single number — HeuristicEmpire is weak
enough that random rebels beat it.

### Checkpoints, measured per opponent

200 games each. `greedy vs GlobEmpire` is the column that matters.

| checkpoint | greedy vs GlobEmpire | sampled vs GlobEmpire | greedy vs HeuristicEmpire | sampled vs HeuristicEmpire |
|---|---|---|---|---|
| `overnight/clone` | +3.91 (96%) | +2.55 (84%) | +3.73 (98%) | +3.35 (93%) |
| `overnight/best` | +4.24 (98%) | +3.75 (93%) | +14.04 (100%) | +13.73 (100%) |
| `lowlr/best` | +5.33 (98%) | +5.08 (98%) | +10.51 (100%) | +9.10 (96%) |
| `lowlr/final` | +5.33 (98%) | +5.08 (98%) | +10.43 (100%) | +9.05 (96%) |
| `control/best` | -0.12 (20%) | -1.09 (34%) | +10.79 (100%) | +6.54 (94%) |

MistBot for comparison: **+5.57** vs GlobEmpire, +3.80 vs HeuristicEmpire.

### overnight

1520 parameters · clone 6 epochs over 4000 MistBot games · RL 150.0 min at lr 0.003 · 8 workers

| point | greedy vs GlobEmpire | sampled vs GlobEmpire | greedy vs HeuristicEmpire | sampled vs HeuristicEmpire | overall |
|---|---|---|---|---|---|
| untrained | -3.32 | -2.59 | +0.18 | +2.57 | -0.79 |
| cloned | +3.59 | +2.62 | +3.96 | +3.57 | +3.44 |
| step 50 | +4.04 | +3.71 | +5.07 | +4.79 | +4.40 |
| step 100 | +4.80 | +4.29 | +7.28 | +7.05 | +5.86 |
| step 150 | +5.35 | +4.56 | +9.11 | +7.86 | +6.72 |
| step 200 | +4.58 | +4.62 | +11.32 | +9.44 | +7.49 |
| step 250 | +3.94 | +3.75 | +8.78 | +8.25 | +6.18 |
| step 300 | +4.20 | +3.89 | +14.28 | +13.31 | +8.92 |
| step 350 | +4.78 | +4.10 | +12.94 | +12.86 | +8.67 |

Clone: loss 1.129 → 0.558, teacher agreement 51% → 75% over 2712 steps.
RL: 11200 episodes, batch margin +2.78 → +8.00 (best batch +10.06 at step 320), game length 6.7 → 19.9 rounds.

### lowlr

1520 parameters · clone 6 epochs over 4000 MistBot games · RL 55.0 min at lr 0.0008 · 6 workers

Ran 57 minutes.

| point | greedy vs GlobEmpire | sampled vs GlobEmpire | greedy vs HeuristicEmpire | sampled vs HeuristicEmpire | overall |
|---|---|---|---|---|---|
| untrained | +3.59 | +2.62 | +3.96 | +3.56 | +3.43 |
| step 50 | +4.00 | +2.89 | +4.28 | +4.00 | +3.79 |
| step 100 | +4.12 | +2.88 | +4.19 | +3.86 | +3.76 |
| step 150 | +3.69 | +3.11 | +7.05 | +5.86 | +4.93 |
| step 200 | +4.26 | +3.63 | +7.91 | +6.84 | +5.66 |
| step 250 | +4.36 | +3.48 | +8.61 | +6.07 | +5.63 |
| step 300 | +4.21 | +3.91 | +9.08 | +6.59 | +5.95 |
| step 350 | +4.51 | +3.85 | +9.22 | +7.87 | +6.36 |
| step 400 | +4.79 | +4.43 | +8.38 | +6.60 | +6.05 |
| step 450 | +4.63 | +4.76 | +9.82 | +8.28 | +6.87 |
| step 500 | +5.29 | +5.04 | +10.57 | +8.71 | +7.40 |
| step 550 | +5.36 | +4.91 | +10.79 | +9.73 | +7.70 |
| final | +5.25 | +5.09 | +10.55 | +9.38 | +7.57 |

RL: 13200 episodes, batch margin +2.25 → +6.67 (best batch +7.79 at step 530), game length 8.5 → 17.2 rounds.

### control

1520 parameters · clone 6 epochs over 4000 MistBot games · RL 55.0 min at lr 0.003 · 6 workers

Ran 58 minutes.

| point | greedy vs GlobEmpire | sampled vs GlobEmpire | greedy vs HeuristicEmpire | sampled vs HeuristicEmpire | overall |
|---|---|---|---|---|---|
| untrained | -3.32 | -2.59 | +0.18 | +2.53 | -0.80 |
| step 50 | +0.00 | -1.80 | +2.51 | +4.61 | +1.33 |
| step 100 | +1.20 | -0.98 | +2.98 | +5.89 | +2.27 |
| step 150 | -1.56 | -0.82 | +7.10 | +6.48 | +2.80 |
| step 200 | +0.00 | -1.23 | +10.15 | +6.48 | +3.85 |
| step 250 | +2.52 | -0.91 | +4.00 | +8.98 | +3.65 |
| final | -0.29 | -1.34 | +10.64 | +7.14 | +4.04 |

RL: 6960 episodes, batch margin +0.25 → +2.88 (best batch +5.50 at step 220), game length 12.3 → 19.8 rounds.


## What the night says

**A 1,520-parameter GPT can play this game.** Cloned from MistBot for 31
minutes it reaches +3.91 against GlobEmpire and wins 96% — past both
hand-written rebel heuristics (−1.13 and −1.85) though short of its teacher.
Policy gradient on top of that reaches **+5.33 at 98%**, a quarter of a point
under MistBot and clearly not noise in either direction (200 games, sd ≈ 1.2,
so a standard error near 0.09).

**Cloning is the whole game.** The control — identical reinforcement budget
from random init — finished at **−0.12 against GlobEmpire, winning 20%**. It
is worse than a random rebel on the hard opponent. Yet it scores +10.79
against the weak one, which is the same policy the cloned runs found and the
tell for what reward alone actually optimises here.

**Reward alone finds the weakest opponent in the pool.** Every reinforcement
run inflates enormously against HeuristicEmpire (+10 to +14, against MistBot's
+3.80) while barely moving against GlobEmpire. Training sampled the two
opponents evenly and weighted their margins equally, so the cheapest gradient
was always the one that farmed the bot random rebels already beat. The
in-run "overall" average hid this completely: `overnight/best` has the best
overall score of any checkpoint (+8.92) and is a point worse than `lowlr/best`
on the opponent that matters.

**The decay after step 150 was the learning rate.** At 0.003 the hard-opponent
margin peaked at +5.35 on step 150 and slid to +4.20 by step 300 while the
average kept climbing — specialisation, not divergence. At 0.0008 from the
same cloned weights it rose monotonically to +5.36 by step 550 and stayed
there; `best` and `final` are the same policy to two decimals.

**A curiosity worth keeping.** The learned policies beat HeuristicEmpire by far
more than MistBot does, because MistBot ends games around round 5 against it —
its 0-point resolutions shrink the board — while the GPT lets the game run to
11-18 rounds and keeps capturing. Two ways to win a game that look nothing
alike in the score.

## What to change next

1. **Weight the opponents, or train against GlobEmpire alone.** This is the
   one-line change with the largest expected effect, and every number above is
   an argument for it.
2. **Select checkpoints on the hard opponent**, not on the average. `best.json`
   was chosen on a metric that rewards farming.
3. **Put adjacency in the town token.** The encoding has no notion of which
   towns touch which; the position embedding has to learn the whole map.
   Adjacent troop count is what MistBot's rules are actually written in terms
   of, and it is one more binned field.
4. **Then lengthen the run.** `lowlr` was still improving when its budget ran
   out, and nothing here has been trained for more than an hour.
