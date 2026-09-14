"""MistBot's games, written down as token sequences the policy can be taught.

A document is one Insurgency turn: the board prompt, then the four decisions
that turn takes — resolve, then a town for each card in hand order. Each step
records the legal set as well as the target, because the policy samples from a
softmax over legal actions only and the teacher has to be scored against the
same distribution. Train on a different support and the cloned weights are the
weights of a policy nobody ever played.
"""

from __future__ import annotations

import json
import random
from pathlib import Path

from sim.bots import GlobEmpire, HeuristicEmpire, MistBot
from sim.engine import Side, apply_empire_turn, apply_insurgency_turn, new_game, prepare_turn

from .encoding import vocabulary

EMPIRE_BOTS = {"glob": GlobEmpire, "heuristic": HeuristicEmpire}


def turn_document(vocab, state, hand_size, turn) -> dict:
    """One teacher turn: the prompt, and each decision with its legal set."""
    prompt = vocab.prompt(state, hand_size)
    steps = []

    resolve_token = vocab.skip if turn.resolve is None else vocab.action_id(turn.resolve)
    steps.append({
        "legal": vocab.legal_resolutions(state),
        "target": resolve_token,
    })

    town_of_index = {}
    for town_id, indices in turn.placements.items():
        for index in indices:
            town_of_index[index] = town_id

    for index in range(len(state.hand)):
        legal = vocab.legal_placements(state, turn.resolve)
        if not legal or index not in town_of_index:
            break
        steps.append({"legal": legal, "target": vocab.action_id(town_of_index[index])})

    return {"prompt": prompt, "steps": steps}


def collect(scenario, games: int = 500, seed: int = 0,
            empire_bots: tuple[str, ...] = ("glob", "heuristic")) -> list[dict]:
    """Play MistBot against each Empire bot in turn and record its turns.

    Both opponents on purpose: a policy cloned from games against one of them
    has only ever seen the positions that one produces, and `GlobEmpire` in
    particular plays a very narrow game.
    """
    vocab = vocabulary(scenario)
    documents = []

    for index in range(games):
        rng = random.Random(seed * 100003 + index)
        name = empire_bots[index % len(empire_bots)]
        empire = EMPIRE_BOTS[name](rng)
        rebels = MistBot(rng)

        state = new_game(scenario, rng)
        for _ in range(2000):
            prepare_turn(state)
            if state.game_over:
                break
            if state.to_move is Side.INSURGENCY:
                turn = rebels.choose(state)
                documents.append(turn_document(vocab, state, scenario.hand_size, turn))
                apply_insurgency_turn(state, turn)
            else:
                apply_empire_turn(state, empire.choose(state))

    return documents


def save(documents: list[dict], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w") as handle:
        for document in documents:
            handle.write(json.dumps(document, separators=(",", ":")) + "\n")


def load(path: Path) -> list[dict]:
    with path.open() as handle:
        return [json.loads(line) for line in handle if line.strip()]
