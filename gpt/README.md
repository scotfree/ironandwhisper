# A GPT that plays the Insurgency

An experiment, not a bot for the game. Nothing in `sim/` or `modules/` imports
this package, and `tools/deploy.json` excludes it from BGA.

The question: can Karpathy's dependency-free ~200-line GPT — scalar autograd,
no numpy, about 1,500 parameters here — learn to play the rebel side from
reward alone? The model is imported unmodified from the sibling `lcgpt`
project, where the notebooks quote it by line number; see `shim.py`.

## What is here

| file | job |
|---|---|
| `shim.py` | find and import `karpathy.py`, never edit or copy it |
| `encoding.py` | board → tokens, actions → tokens, and what is legal |
| `policy.py` | `GptPolicy.choose(state)` — the same interface the other bots implement |
| `corpus.py` | MistBot's games written down as token sequences |
| `train.py` | both objectives, and the worker each runs in |
| `optim.py` | Adam, lifted from `karpathy.py`'s `train()` |
| `evaluate.py` | margin against each Empire bot, sampled and greedy |
| `experiment.py` | the driver: clone, then policy gradient, checkpointing as it goes |

```bash
sim/.venv/bin/python -m gpt.experiment --name overnight --rl-minutes 150
```

## The encoding, and why it is shaped like that

Every token costs a forward pass, so the board has to be short. One token per
town carries **both sides at once** — a binned `(card presence, troops)` pair —
which fits the whole board in twelve tokens where a token per side would need
twenty-four and roughly double the cost of everything. Bins are coarse
(0, 1, 2, 3+) because at baseline a card is worth 0 or 1 and a troop 1.

```
pos  0      BOS
pos  1..12  one token per town, in map order
pos 13..15  the hand, one token per card
pos 16      the resolve choice is read out here
pos 17..19  and a town for card 0, 1, 2
```

Vocabulary 37, `block_size` 20, `n_embd` 8, one layer, two heads: **1,520
parameters**. At `n_embd` 16 the same thing costs 0.12 s per turn against
0.029 s, which is the whole reason the model is this small.

**Town identity lives in the position embedding, so the map's geography has to
be learned rather than given.** Adjacency is not in the representation at all —
and adjacency is precisely what MistBot found decisive. That is the first thing
to change if the policy plateaus: a third field in the town token holding the
binned adjacent troop count.

## The loss

Cross-entropy and policy gradient are the same expression with a different
coefficient:

```
clone:     L = -(1/n) Σ_t       log π(teacher_t | s_t)
reinforce: L = -(1/n) Σ_t A_t · log π(a_t | s_t)  -  β Σ_t H_t
```

so cloning is policy gradient with the advantage clamped to 1 — which is why
the cloned weights hand straight over to the reinforcement run.

`A_t` is reward-to-go minus a running baseline, over a standard deviation.
Reward for an Insurgency turn is the change in margin across the whole round,
so credit lands on the turn that caused it. The baseline is an exponential
moving average rather than a batch mean, because each worker backwards an
episode the moment it ends and nothing in the batch exists to average over yet;
the bias is small and it is what keeps eight workers inside memory.

**The printed policy-gradient loss is not a training curve.** Its value is
meaningless — only its gradient means anything. Watch the margin.

## Reading the numbers

Margin is rebel score minus Empire score. The hand-written bots, 200 games each:

| rebels | vs GlobEmpire | vs HeuristicEmpire |
|---|---|---|
| random | −1.85 | +3.88 |
| heuristic | −1.13 | +6.83 |
| **MistBot** | **+5.57** | **+3.80** |

Note the second column: `HeuristicEmpire` is weak enough that random rebels
beat it, so an average across both opponents flatters everything. Read the
per-opponent rows. `greedy/GlobEmpire` is the honest single number.
