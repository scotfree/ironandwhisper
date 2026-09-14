"""The GPT as an Insurgency bot.

`GptPolicy.choose(state) -> InsurgencyTurn` is the same interface the hand-
written bots implement, so the policy can be dropped into `play_game` or
`sim.run` and measured against exactly the same opponents by exactly the same
code. Nothing here knows about training.

Two things are worth saying about the sampling:

**Illegal actions are not sampled and not penalised — they are absent.** The
softmax runs over the legal subset only, so the policy is a distribution over
legal moves by construction. Masking is not a shortcut: the whole hand must be
placed every turn (Decision 6), so a policy that can emit an illegal placement
cannot produce a legal turn at all, and would spend its first few thousand
games learning what the rules already know.

**The same masking must be used when the log-probability is recorded**, or the
gradient belongs to a different policy than the one that played.
"""

from __future__ import annotations

import math
import random
from dataclasses import dataclass, field

from sim.engine import GameState, InsurgencyTurn

from .encoding import Vocabulary, vocabulary
from .shim import K


def new_config(scenario, n_embd: int = 8, n_head: int = 2, n_layer: int = 1,
               seed: int | None = 0, **overrides) -> dict:
    """A model sized for the game, with the vocabulary the encoding needs."""
    vocab = vocabulary(scenario)
    config = K.CONFIG_DEFAULTS.copy()
    config.update(
        vocab_size=vocab.size,
        BOS=vocab.bos,
        block_size=vocab.block_size,
        n_embd=n_embd,
        n_head=n_head,
        n_layer=n_layer,
    )
    config.update(overrides)
    K.init_params(config, seed)
    return config


def params(config) -> list:
    return [p for mat in config['state_dict'].values() for row in mat for p in row]


def get_weights(config) -> list[float]:
    return [p.data for p in params(config)]


def set_weights(config, weights: list[float]) -> None:
    for p, w in zip(params(config), weights):
        p.data = w


def take_grads(config) -> list[float]:
    """Read the accumulated gradients and clear them."""
    out = []
    for p in params(config):
        out.append(p.grad)
        p.grad = 0
    return out


@dataclass
class Decision:
    """One sampled action, kept so the episode can be scored afterwards."""
    logp: object          # a Value: log pi(a | s)
    entropy: object       # a Value, or None when not recording
    turn: int


@dataclass
class GptPolicy:
    """An Insurgency bot backed by the GPT. Also the thing training updates."""

    config: dict
    vocab: Vocabulary
    hand_size: int
    rng: random.Random = field(default_factory=random.Random)
    greedy: bool = False
    record: bool = False
    trace: list = field(default_factory=list)
    turn: int = 0

    @classmethod
    def build(cls, scenario, config=None, rng=None, **kwargs):
        return cls(
            config=config if config is not None else new_config(scenario),
            vocab=vocabulary(scenario),
            hand_size=scenario.hand_size,
            rng=rng or random.Random(),
            **kwargs,
        )

    # -- one turn ----------------------------------------------------------

    def choose(self, state: GameState) -> InsurgencyTurn:
        vocab = self.vocab
        n_layer = self.config['n_layer']
        keys = [[] for _ in range(n_layer)]
        values = [[] for _ in range(n_layer)]

        tokens = vocab.prompt(state, self.hand_size)
        logits = None
        for pos, token in enumerate(tokens):
            logits = K.gpt(self.config, token, pos, keys, values)
        pos = len(tokens)

        # 1. resolve, or decline to
        token = self._act(logits, vocab.legal_resolutions(state))
        resolve = vocab.town_of_action(token)

        # 2. one placement per card in hand, in hand order
        placements: dict[str, list[int]] = {}
        for index in range(len(state.hand)):
            legal = vocab.legal_placements(state, resolve)
            if not legal:
                break  # the resolution closed the last town; nothing may be placed
            logits = K.gpt(self.config, token, pos, keys, values)
            pos += 1
            token = self._act(logits, legal)
            town_id = vocab.town_of_action(token)
            placements.setdefault(town_id, []).append(index)

        self.turn += 1
        return InsurgencyTurn(placements=placements, resolve=resolve)

    # -- sampling ----------------------------------------------------------

    def _act(self, logits, legal: list[int]) -> int:
        """Sample one legal action, recording its log-probability if training."""
        probs = K.softmax([logits[i] for i in legal])
        weights = [p.data for p in probs]

        if self.greedy:
            choice = max(range(len(legal)), key=lambda i: weights[i])
        else:
            choice = self.rng.choices(range(len(legal)), weights=weights)[0]

        if self.record:
            entropy = -sum(p * p.log() for p in probs) if len(probs) > 1 else None
            self.trace.append(Decision(probs[choice].log(), entropy, self.turn))

        return legal[choice]

    def reset(self) -> None:
        self.trace = []
        self.turn = 0
