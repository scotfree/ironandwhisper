"""Replay a real game from its BGA notification log, and ask the bots about it.

    sim/.venv/bin/python -m sim.replay logs/975612.json            # replay, check
    sim/.venv/bin/python -m sim.replay logs/975612.json --compare  # and compare

A log from `tools/fetch-log.py` is every notification the game sent, in order.
This rebuilds the game from it in the simulator, one whole turn at a time, and
checks at every step that the simulator agrees with what the server said
happened: each resolution's winner and presences, the troops standing after
every Empire turn, what starved and what was marked. A disagreement is a
`ReplayMismatch`, and it means the parser, the engine or the port is wrong —
the same claim the parity fixtures make, on positions real people reached.

With `--compare`, each turn is also put to the bot for that side — Glob2 for
the Empire, Mist2 for the rebels, the most advanced of each — and its choice is
printed beside the one actually made. On the bot's own turns the two must be
identical, since both bots are deterministic: that is parity on a real game. On
a person's turns every difference is a candidate rule for issue #18.

**Hidden cards.** A log is one player's view, but nearly every card placed is
turned face up before the end — by a look, a resolution or the final sweep —
and every one of those notifications carries the card's type. A card is
identified by its id throughout, so its value can be filled in from wherever it
was eventually revealed. A placed card that never turned over would stop the
replay; in practice every placed card ends in a resolution, since a town with a
card in it is never left out of the sweep.

**Hands.** The rebels place their whole hand every turn (Decision 6), so the
hand a turn was played from *is* the set of cards placed on it. The bot is
given that same hand, in the order the log lists it.
"""

from __future__ import annotations

import argparse
import copy
import json
import random
from collections import Counter
from dataclasses import dataclass, field
from pathlib import Path

from .bots import Glob2Empire, Mist2Insurgency
from .config import Scenario, load_scenario
from .engine import (
    Card,
    EmpireTurn,
    GameState,
    InsurgencyTurn,
    Side,
    _end_game,
    apply_empire_turn,
    apply_insurgency_turn,
    new_game,
)

BOT_PLAYER_ID = 0


class ReplayMismatch(Exception):
    """The simulator and the log disagree about what happened."""


@dataclass
class Decision:
    """One turn as it was played, and the position it was played from."""
    round: int
    side: Side
    by_bot: bool
    before: GameState
    turn: InsurgencyTurn | EmpireTurn


@dataclass
class Replay:
    table: int | None
    decisions: list[Decision]
    final: GameState
    # What the log says the score was, worked out from the log alone.
    logged_scores: dict[Side, int]
    end_reason: str | None = None


# -- reading the log --------------------------------------------------------

def notifications(log: dict) -> list[dict]:
    """Every notification in the order the game sent it, across channels."""
    packets = log['data']['logs']
    ordered = sorted(packets, key=lambda p: int(p['packet_id']))
    return [n for packet in ordered for n in packet['data']]


def card_types(notes: list[dict]) -> dict[int, str]:
    """Every card id whose type the log ever shows, wherever it showed it."""
    known: dict[int, str] = {}

    def learn(card: dict) -> None:
        if card.get('type'):
            known[int(card['id'])] = card['type']

    for note in notes:
        args = note.get('args') or {}
        kind = note['type']
        if kind == 'handDrawn':
            for card in args.get('hand', []):
                learn(card)
        elif kind == 'cardsRevealed':
            for cards in args.get('revealed', {}).values():
                for card in cards:
                    learn(card)
        elif kind == 'townResolved':
            for card in args.get('pile', []):
                learn(card)
    return known


def logged_scores(notes: list[dict]) -> dict[Side, int]:
    """The final score according to the log, without the simulator's help."""
    scores = {Side.EMPIRE: 0, Side.INSURGENCY: 0}
    for note in notes:
        args = note.get('args') or {}
        if note['type'] == 'townResolved':
            scores[Side(args['winner'])] += int(args['points'])
        elif note['type'] == 'troopsStarved':
            scores[Side.INSURGENCY] += int(args['points'])
    return scores


def build_cards(scenario: Scenario, known: dict[int, str]) -> dict[int, Card]:
    """A Card for every id in the deck.

    Ids the log never revealed are given the types left over once the known
    ones are taken out of the deck's composition. Which unknown id gets which
    type is arbitrary, but those cards were never placed — so nothing that
    happened depended on them, and the deck's make-up is still exact.
    """
    total = sum(scenario.deck.values())
    remaining = Counter(scenario.deck)
    for type_id in known.values():
        remaining[type_id] -= 1
    if any(n < 0 for n in remaining.values()):
        raise ReplayMismatch(f'the log shows more cards of a type than the deck holds: {known}')
    leftovers = [t for t, n in sorted(remaining.items()) for _ in range(n)]

    cards: dict[int, Card] = {}
    for uid in range(1, total + 1):
        type_id = known[uid] if uid in known else leftovers.pop()
        cards[uid] = Card(uid=uid, type_id=type_id,
                          presence=scenario.card_types[type_id].presence)
    return cards


# -- replaying --------------------------------------------------------------

def _counts(raw) -> dict[str, int]:
    """A town => count map from the log. PHP encodes an empty array as `[]`."""
    return {town: int(n) for town, n in raw.items()} if raw else {}


class _Replayer:
    def __init__(self, log: dict, scenario: Scenario):
        self.notes = notifications(log)
        self.scenario = scenario
        self.cards = build_cards(scenario, card_types(self.notes))

        self.state = new_game(scenario, random.Random(0))
        self.state.deck = sorted(self.cards.values(), key=lambda c: c.uid)
        self.state.hand = []
        self.decisions: list[Decision] = []

        self.side = scenario.first_player
        self._fresh_turn()
        self.end_reason: str | None = None

    def _fresh_turn(self) -> None:
        self.resolve: str | None = None
        self.resolution_note: dict | None = None
        self.placements: dict[str, list[int]] = {}
        self.produce: dict[str, int] = {}
        self.moves: list[tuple[str, str, int]] = []
        self.disband: dict[str, int] = {}
        self.actor: int | None = None
        self.troops_after: dict[str, int] | None = None
        self.starving_after: dict[str, int] | None = None
        self.started = False

    def run(self) -> None:
        for note in self.notes:
            args = note.get('args') or {}
            kind = note['type']

            if kind == 'townResolved':
                if args.get('declaredBy') is None:
                    continue  # the end-of-game sweep, checked in _finish_game
                if args['declaredBy'] != self.side.value:
                    raise ReplayMismatch(
                        f"T{args['turn']}: {args['declaredBy']} resolved {args['town_id']} "
                        f'during the {self.side.value} turn')
                self.resolve = args['town_id']
                self.resolution_note = args
                self.started = True

            elif kind == 'cardsPlaced':
                self._expect(Side.INSURGENCY, kind)
                self.actor = int(args['player_id'])
                self.placements = {town: [int(i) for i in ids]
                                   for town, ids in (args['cards'] or {}).items()}
                self._finish_insurgency_turn()

            elif kind == 'empireMoved':
                self._expect(Side.EMPIRE, kind)
                self.actor = int(args['player_id'])
                self.produce = _counts(args.get('produced'))
                self.moves = [(m['from'], m['to'], int(m['count'])) for m in args.get('moves', [])]
                self.troops_after = _counts(args['troops'])
                self.started = True

            elif kind == 'troopsStarved':
                self.disband = _counts(args['losses'])
                self.troops_after = _counts(args['troops'])

            elif kind == 'starvationWarning':
                self._expect(Side.EMPIRE, kind)
                self.starving_after = _counts(args.get('starving'))
                self._finish_empire_turn()

            elif kind == 'gameEnding':
                self.end_reason = args.get('reason')
                if self.started:
                    # A resolution that closed the last open town ends the
                    # turn before anything else is sent.
                    if self.side is Side.INSURGENCY:
                        self._finish_insurgency_turn()
                    else:
                        self._finish_empire_turn()
                self._finish_game()
                return

    def _expect(self, side: Side, kind: str) -> None:
        if self.side is not side:
            raise ReplayMismatch(f'{kind} arrived during the {self.side.value} turn')

    def _snapshot(self) -> GameState:
        # deepcopy rather than clone(): clone() does not carry `starving`.
        return copy.deepcopy(self.state)

    def _finish_insurgency_turn(self) -> None:
        hand_ids = [uid for ids in self.placements.values() for uid in ids]
        self.state.hand = [self.cards[uid] for uid in hand_ids]
        self.state.deck = [c for c in self.state.deck if c.uid not in set(hand_ids)]
        index = {uid: i for i, uid in enumerate(hand_ids)}
        turn = InsurgencyTurn(
            placements={town: [index[uid] for uid in ids] for town, ids in self.placements.items()},
            resolve=self.resolve,
        )
        self._apply(Side.INSURGENCY, turn)

    def _finish_empire_turn(self) -> None:
        turn = EmpireTurn(produce=self.produce, moves=self.moves,
                          resolve=self.resolve, disband=self.disband)
        self._apply(Side.EMPIRE, turn)

    def _apply(self, side: Side, turn) -> None:
        self.state.to_move = side
        before = self._snapshot()
        round_number = self.state.round_number
        self.decisions.append(Decision(
            round=round_number, side=side,
            by_bot=self.actor == BOT_PLAYER_ID if self.actor is not None
                   else self._side_is_bot(side),
            before=before, turn=turn,
        ))

        if side is Side.INSURGENCY:
            apply_insurgency_turn(self.state, turn)
        else:
            apply_empire_turn(self.state, turn)

        self._check_resolution(round_number)
        if side is Side.EMPIRE:
            self._check_troops(round_number)

        self.side = side.other
        self._fresh_turn()

    def _side_is_bot(self, side: Side) -> bool:
        # A turn that was only a resolution names nobody; any other turn by the
        # same side does.
        for decision in self.decisions:
            if decision.side is side:
                return decision.by_bot
        return False

    def _check_resolution(self, round_number: int) -> None:
        note = self.resolution_note
        if note is None:
            return
        town = self.state.towns[note['town_id']]
        simulated = (town.winner.value if town.winner else None,
                     town.resolved_card_presence, town.resolved_troop_presence)
        logged = (note['winner'], int(note['cardPresence']), int(note['troopPresence']))
        if simulated != logged:
            raise ReplayMismatch(
                f"R{round_number} {town.id}: simulator resolved it {simulated}, log says {logged}")

    def _check_troops(self, round_number: int) -> None:
        if self.troops_after is not None:
            simulated = {tid: t.troops for tid, t in self.state.towns.items()}
            if simulated != self.troops_after:
                diff = {tid: (simulated[tid], n) for tid, n in self.troops_after.items()
                        if simulated.get(tid) != n}
                raise ReplayMismatch(
                    f'R{round_number}: troops after the Empire turn (sim, log): {diff}')
        if self.starving_after is not None:
            simulated = {tid: t.starving for tid, t in self.state.towns.items() if t.starving}
            if simulated != self.starving_after:
                raise ReplayMismatch(
                    f'R{round_number}: starvation marks: sim {simulated}, log {self.starving_after}')

    def _finish_game(self) -> None:
        sweep = [n['args'] for n in self.notes
                 if n['type'] == 'townResolved' and (n.get('args') or {}).get('declaredBy') is None]
        _end_game(self.state, self.end_reason or 'end of log')
        for args in sweep:
            town = self.state.towns[args['town_id']]
            if (town.winner.value if town.winner else None) != args['winner']:
                raise ReplayMismatch(
                    f"sweep: simulator gives {args['town_id']} to {town.winner}, log to {args['winner']}")


def replay(log: dict, scenario: Scenario | None = None) -> Replay:
    scenario = scenario or load_scenario('baseline')
    replayer = _Replayer(log, scenario)
    replayer.run()

    expected = logged_scores(replayer.notes)
    if replayer.state.scores != expected:
        raise ReplayMismatch(
            f'final score: simulator {replayer.state.scores}, log {expected}')

    packets = log['data']['logs']
    table = int(packets[0]['table_id']) if packets else None
    return Replay(table=table, decisions=replayer.decisions, final=replayer.state,
                  logged_scores=expected, end_reason=replayer.end_reason)


def load(path: str | Path, scenario: Scenario | None = None) -> Replay:
    return replay(json.loads(Path(path).read_text()), scenario)


# -- asking the bots --------------------------------------------------------

def bot_turn(decision: Decision):
    """What the advanced bot for this side would have played from here."""
    state = copy.deepcopy(decision.before)
    if decision.side is Side.EMPIRE:
        return Glob2Empire().choose(state)
    return Mist2Insurgency().choose(state)


def describe(decision: Decision, turn) -> str:
    """A turn in plain words, with cards named by value so two can be compared."""
    state = decision.before
    parts: list[str] = []
    if turn.resolve:
        parts.append(f'resolve {turn.resolve}')
    if isinstance(turn, InsurgencyTurn):
        for town, indices in sorted(turn.placements.items()):
            values = ''.join(str(state.hand[i].presence) for i in indices)
            parts.append(f'{town}+[{values}]')
    else:
        for town, n in sorted(turn.produce.items()):
            parts.append(f'build {n} {town}')
        for src, dst, n in sorted(turn.moves):
            parts.append(f'{n} {src}>{dst}')
    return ', '.join(parts) or 'nothing'


def same(decision: Decision, a, b) -> bool:
    """Whether two turns are the same decision.

    Placement is compared as the values going to each town, in order, since two
    cards of one value are the same decision. Disband is left out: a person's
    client never sends one, so it records only where the loss happened to fall.
    """
    if a.resolve != b.resolve:
        return False
    if isinstance(a, InsurgencyTurn):
        return describe(decision, a) == describe(decision, b)
    return dict(a.produce) == dict(b.produce) and sorted(a.moves) == sorted(b.moves)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.strip().splitlines()[0])
    parser.add_argument('logs', nargs='+')
    parser.add_argument('--compare', action='store_true',
                        help='print what Glob2 / Mist2 would have done at every turn')
    args = parser.parse_args()

    for path in args.logs:
        result = load(path)
        empire, rebels = result.logged_scores[Side.EMPIRE], result.logged_scores[Side.INSURGENCY]
        print(f'{path}: table {result.table}, {len(result.decisions)} turns, '
              f'Empire {empire} - Rebels {rebels} ({result.end_reason}); replay agrees')
        if not args.compare:
            continue

        agree = Counter()
        for decision in result.decisions:
            bot = bot_turn(decision)
            who = 'bot ' if decision.by_bot else 'human'
            match = same(decision, decision.turn, bot)
            agree[(who, match)] += 1
            mark = '=' if match else '≠'
            print(f'  R{decision.round:<2} {decision.side.value:<10} {who} {mark} '
                  f'{describe(decision, decision.turn)}')
            if not match:
                print(f'  {"":<20}   bot:  {describe(decision, bot)}')
        summary = ', '.join(f'{who.strip()} {"agrees" if m else "differs"} {n}'
                            for (who, m), n in sorted(agree.items()))
        print(f'  {summary}')


if __name__ == '__main__':
    main()
