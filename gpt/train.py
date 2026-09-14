"""The two objectives, and the worker pool they share.

Cloning and policy gradient are the same computation with a different
coefficient in front of each log-probability:

    clone:     L = -(1/n) * sum_t      log pi(teacher_t | s_t)
    reinforce: L = -(1/n) * sum_t A_t * log pi(a_t | s_t)   - beta * sum_t H_t

so cross-entropy is policy gradient with the advantage clamped to 1 and the
action fixed to the teacher's. That is not a cute observation — it is why the
cloned weights can be handed straight to the policy-gradient run without
touching anything else.

**The number these print is not a training curve.** A cross-entropy falls as a
clone improves, but the policy-gradient surrogate's *value* is meaningless: it
is an advantage-weighted log-probability whose gradient happens to be the one
we want. Watch the margin against the Empire bots instead.

Workers exist because episodes are independent and the weights are 1,520
floats: each process plays or replays its share, sends back a flat gradient
vector, and the main process sums them and takes one Adam step. Every worker
holds one episode graph at a time — a few tens of MB — because the whole
episode's graph has to stay alive until the game ends and the reward is known.
"""

from __future__ import annotations

import math
import random

from sim.config import load_scenario
from sim.engine import Side, apply_empire_turn, apply_insurgency_turn, new_game, prepare_turn

from .encoding import vocabulary
from .policy import GptPolicy, new_config, params, set_weights, take_grads
from .shim import K

# Per-process state, built once by the pool initializer.
_W: dict = {}


def worker_init(scenario_id: str, model: dict, empire_bots: tuple[str, ...]) -> None:
    import sim.bots as bots

    scenario = load_scenario(scenario_id)
    _W['scenario'] = scenario
    _W['vocab'] = vocabulary(scenario)
    _W['config'] = new_config(scenario, **model)
    _W['empire'] = [getattr(bots, name) for name in empire_bots]


# -- objective 1: clone the teacher ----------------------------------------

def clone_task(job: tuple) -> tuple:
    """Teacher-forced cross-entropy over one slice of documents."""
    weights, documents = job
    config, vocab = _W['config'], _W['vocab']
    set_weights(config, weights)

    total_loss, correct, steps = 0.0, 0, 0
    for document in documents:
        keys = [[] for _ in range(config['n_layer'])]
        values = [[] for _ in range(config['n_layer'])]
        logits = None
        for pos, token in enumerate(document['prompt']):
            logits = K.gpt(config, token, pos, keys, values)
        pos = len(document['prompt'])

        losses = []
        previous = None
        for step in document['steps']:
            if previous is not None:
                logits = K.gpt(config, previous, pos, keys, values)
                pos += 1
            legal = step['legal']
            probs = K.softmax([logits[i] for i in legal])
            index = legal.index(step['target'])
            losses.append(-probs[index].log())
            correct += max(range(len(legal)), key=lambda i: probs[i].data) == index
            steps += 1
            previous = step['target']

        if not losses:
            continue
        loss = (1 / len(losses)) * sum(losses)
        loss.backward()
        total_loss += loss.data

    return take_grads(config), total_loss, len(documents), correct, steps


# -- objective 2: policy gradient -------------------------------------------

def play_episode(config, scenario, vocab, empire_bot, rng) -> tuple:
    """One game with the policy recording, returning its decisions and rewards.

    The reward for an Insurgency turn is the change in margin across the whole
    round — its own resolution plus whatever the Empire's reply cost it — so
    credit lands on the turn that caused it rather than on the end of the game.
    """
    policy = GptPolicy(config=config, vocab=vocab, hand_size=scenario.hand_size,
                       rng=rng, record=True)
    state = new_game(scenario, rng)
    empire = empire_bot(rng)

    rewards: list[float] = []
    marks: list[int] = []      # index into policy.trace where each turn starts
    margin = 0.0

    for _ in range(2000):
        prepare_turn(state)
        if state.game_over:
            break
        if state.to_move is Side.INSURGENCY:
            marks.append(len(policy.trace))
            rewards.append(0.0)
            apply_insurgency_turn(state, policy.choose(state))
        else:
            apply_empire_turn(state, empire.choose(state))
            now = state.scores[Side.INSURGENCY] - state.scores[Side.EMPIRE]
            if rewards:
                rewards[-1] += now - margin
            margin = now

    final = state.scores[Side.INSURGENCY] - state.scores[Side.EMPIRE]
    if rewards:
        rewards[-1] += final - margin       # the end-of-game sweep lands here
    return policy, rewards, marks, final


def reinforce_task(job: tuple) -> tuple:
    """Play `games` episodes and accumulate the policy gradient over them."""
    weights, seed, games, baseline, scale, gamma, entropy_beta = job
    config, vocab, scenario = _W['config'], _W['vocab'], _W['scenario']
    set_weights(config, weights)
    rng = random.Random(seed)

    margins, returns, rounds = [], [], []
    for _ in range(games):
        empire_bot = rng.choice(_W['empire'])
        policy, rewards, marks, final = play_episode(config, scenario, vocab, empire_bot, rng)

        # reward-to-go, so no action is credited with points scored before it
        to_go = [0.0] * len(rewards)
        running = 0.0
        for t in range(len(rewards) - 1, -1, -1):
            running = rewards[t] + gamma * running
            to_go[t] = running

        terms = []
        for turn_index, start in enumerate(marks):
            end = marks[turn_index + 1] if turn_index + 1 < len(marks) else len(policy.trace)
            advantage = (to_go[turn_index] - baseline) / scale
            for decision in policy.trace[start:end]:
                terms.append(advantage * decision.logp)
                if entropy_beta and decision.entropy is not None:
                    terms.append(entropy_beta * decision.entropy)

        if terms:
            loss = (-1 / len(terms)) * sum(terms)
            loss.backward()

        margins.append(final)
        returns.extend(to_go)
        rounds.append(len(marks))

    return take_grads(config), margins, returns, rounds
