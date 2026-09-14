"""The board as a short sequence of tokens, and the actions as more tokens.

The whole design constraint is context length: every position costs a forward
pass through the model, so the board has to say what it needs to say in about a
dozen tokens. Hence **one token per town carrying both sides at once** — a
binned (card presence, troops) pair — rather than one token each. Two tokens
per town would double the prompt and roughly double the cost of everything.

Binning is deliberately coarse (0, 1, 2, 3+). At baseline a card is worth 0 or
1 and a troop 1, so a town rarely holds more than three of either, and the
difference between four and five of something has never decided a game. The
bins can be widened later; the vocabulary is computed, not hard-coded.

The sequence for one Insurgency turn, at `block_size` 20:

    pos  0      BOS
    pos  1..12  one token per town, in map order
    pos 13..15  the hand, one token per card (PAD if short)
    pos 16      <- the model's resolve choice is read out here
    pos 17..19  <- and its placement for card 0, 1, 2

Positions carry town identity, so the map's geography has to be learned through
the position embedding. That is a real cost of the encoding and the first thing
to revisit if the policy plateaus: `adjacent troop sum` is what MistBot found
decisive, and it is not in this representation at all.

The board tokens are *not* re-encoded after the resolve choice, even though
resolving changes the board. The chosen action is fed back in as the next
token, so the model can see what it did; re-encoding would cost another twelve
forward passes per turn for one town's worth of change.
"""

from __future__ import annotations

from sim.engine import GameState, Side

PRESENCE_BINS = 4   # 0, 1, 2, 3+
TROOP_BINS = 4      # 0, 1, 2, 3+
HAND_BINS = 4       # a card worth 0, 1, 2, 3+


def _bin(value: int, bins: int) -> int:
    return min(max(value, 0), bins - 1)


class Vocabulary:
    """Token ids, derived from the scenario rather than hard-coded.

    Ids are laid out in blocks so that `action_id` and `town_of_action` are
    arithmetic rather than lookups.
    """

    def __init__(self, town_ids: list[str]):
        self.town_ids = list(town_ids)
        n = len(self.town_ids)

        self.n_town_states = PRESENCE_BINS * TROOP_BINS
        self.resolved_empire = self.n_town_states
        self.resolved_rebels = self.n_town_states + 1
        self.hand_base = self.n_town_states + 2
        self.pad = self.hand_base + HAND_BINS
        self.action_base = self.pad + 1          # one action token per town
        self.skip = self.action_base + n
        self.bos = self.skip + 1
        self.size = self.bos + 1

        self.block_size = 1 + n + 3 + 4          # BOS + towns + hand + actions

    # -- board -> tokens ---------------------------------------------------

    def town_token(self, state: GameState, town_id: str) -> int:
        town = state.towns[town_id]
        if town.resolved:
            return (self.resolved_empire if town.winner is Side.EMPIRE
                    else self.resolved_rebels)
        presence = _bin(state.card_presence_in(town_id), PRESENCE_BINS)
        troops = _bin(state.troop_presence_in(town_id), TROOP_BINS)
        return presence * TROOP_BINS + troops

    def prompt(self, state: GameState, hand_size: int) -> list[int]:
        tokens = [self.bos]
        tokens += [self.town_token(state, tid) for tid in self.town_ids]
        for i in range(hand_size):
            card = state.hand[i] if i < len(state.hand) else None
            tokens.append(self.pad if card is None
                          else self.hand_base + _bin(card.presence, HAND_BINS))
        return tokens

    # -- actions <-> tokens ------------------------------------------------

    def action_id(self, town_id: str) -> int:
        return self.action_base + self.town_ids.index(town_id)

    def town_of_action(self, token: int) -> str | None:
        """None means 'skip the resolution'."""
        if token == self.skip:
            return None
        return self.town_ids[token - self.action_base]

    # -- what is legal, as token ids ---------------------------------------

    def legal_resolutions(self, state: GameState) -> list[int]:
        """Skip is always legal; a town needs a card of ours in it (Decision 5)."""
        legal = [self.skip]
        legal += [self.action_id(t.id) for t in state.unresolved if t.card_count > 0]
        return legal

    def legal_placements(self, state: GameState, resolved_now: str | None) -> list[int]:
        return [
            self.action_id(t.id) for t in state.unresolved
            if t.id != resolved_now
        ]


def vocabulary(scenario) -> Vocabulary:
    return Vocabulary([t.id for t in scenario.map.towns])
