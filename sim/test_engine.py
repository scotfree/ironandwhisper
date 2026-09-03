"""Rules tests.

These are the executable half of the design doc: each test names the decision
it pins down. If a test here fails after a rules change, the doc needs updating
too — and vice versa.

Run with:  sim/.venv/bin/python -m pytest sim -q
"""

from __future__ import annotations

import random

import pytest

from .config import CardType, GameMap, Scenario, TownDef, Unit, load_scenario
from .engine import (
    Card,
    EmpireTurn,
    IllegalMove,
    InsurgencyTurn,
    Side,
    apply_empire_turn,
    apply_insurgency_turn,
    legal_generation_towns,
    new_game,
    prepare_turn,
    resolve_town,
    winner,
)

INFANTRY = Unit(id="infantry", label="Imperial Infantry", strength=3, movement=1, peek=1)
CARD_TYPES = {
    "influence": CardType(id="influence", label="Influence", influence=1),
    "dummy": CardType(id="dummy", label="Dummy", influence=0),
}


def line_map(n: int = 3) -> GameMap:
    """A path graph: a-b-c-..., the simplest thing with real adjacency."""
    names = [chr(ord("a") + i) for i in range(n)]
    towns = tuple(TownDef(id=x, label=x.upper(), x=i, y=0) for i, x in enumerate(names))
    edges = tuple((names[i], names[i + 1]) for i in range(n - 1))
    return GameMap(id="line", label="Line", towns=towns, edges=edges)


def scenario(**overrides) -> Scenario:
    fields = dict(
        id="test", label="Test", map=line_map(), unit=INFANTRY,
        card_types=CARD_TYPES, hand_size=2,
        deck={"influence": 4, "dummy": 4}, generation_rate=1,
        empire_start={"a": 1}, first_player=Side.INSURGENCY,
        empire_wins_ties=True,
    )
    fields.update(overrides)
    return Scenario(**fields)


def state(**overrides):
    return new_game(scenario(**overrides), random.Random(0))


def seed_pile(st, town_id: str, influence: int = 0, dummies: int = 0) -> None:
    """Put known cards into a pile, newest on top."""
    uid = 1000 + len(st.towns[town_id].pile)
    for _ in range(influence):
        st.towns[town_id].pile.insert(0, Card(uid=uid, type_id="influence", influence=1))
        uid += 1
    for _ in range(dummies):
        st.towns[town_id].pile.insert(0, Card(uid=uid, type_id="dummy", influence=0))
        uid += 1


# ---------------------------------------------------------------------------
# Resolution and scoring
# ---------------------------------------------------------------------------

def test_empire_wins_and_scores_the_influence_it_captured():
    st = state()
    st.towns["a"].troops = 2          # strength 6
    seed_pile(st, "a", influence=4)   # influence 4
    assert resolve_town(st, "a", Side.EMPIRE) is Side.EMPIRE
    assert st.scores[Side.EMPIRE] == 4
    assert st.scores[Side.INSURGENCY] == 0


def test_insurgency_wins_and_scores_the_strength_it_overcame():
    st = state()
    st.towns["a"].troops = 2          # strength 6
    seed_pile(st, "a", influence=7)
    assert resolve_town(st, "a", Side.INSURGENCY) is Side.INSURGENCY
    assert st.scores[Side.INSURGENCY] == 6
    assert st.scores[Side.EMPIRE] == 0


def test_dummies_are_worth_nothing_so_a_bluff_pays_the_empire_nothing():
    """Decision: capture-only scoring. A phantom pile is worth zero."""
    st = state()
    st.towns["a"].troops = 3
    seed_pile(st, "a", dummies=9)
    assert resolve_town(st, "a", Side.EMPIRE) is Side.EMPIRE
    assert st.scores[Side.EMPIRE] == 0


def test_walkover_scores_nothing():
    st = state()
    st.towns["a"].troops = 5
    assert resolve_town(st, "a", Side.EMPIRE) is Side.EMPIRE
    assert st.scores[Side.EMPIRE] == 0


def test_empire_wins_ties():
    """Decision 7."""
    st = state()
    st.towns["a"].troops = 2          # strength 6
    seed_pile(st, "a", influence=6)   # influence 6
    assert resolve_town(st, "a", Side.INSURGENCY) is Side.EMPIRE
    assert st.scores[Side.EMPIRE] == 6


def test_tiebreaker_is_configurable():
    st = state(empire_wins_ties=False)
    st.towns["a"].troops = 2
    seed_pile(st, "a", influence=6)
    assert resolve_town(st, "a", Side.EMPIRE) is Side.INSURGENCY


def test_resolved_towns_freeze_and_cannot_be_resolved_twice():
    st = state()
    st.towns["a"].troops = 1
    resolve_town(st, "a", Side.EMPIRE)
    assert st.towns["a"].resolved
    with pytest.raises(IllegalMove):
        resolve_town(st, "a", Side.EMPIRE)


def test_resolution_reveals_the_pile_to_the_empire():
    """Decision 9: face-up piles make the deck countable."""
    st = state()
    seed_pile(st, "a", influence=2, dummies=2)
    uids = {c.uid for c in st.towns["a"].pile}
    assert not (uids & st.empire_known_uids)
    resolve_town(st, "a", Side.INSURGENCY)
    assert uids <= st.empire_known_uids


# ---------------------------------------------------------------------------
# Presence requirement (Decision 5)
# ---------------------------------------------------------------------------

def test_empire_cannot_resolve_a_town_it_has_no_troops_in():
    st = state()
    st.to_move = Side.EMPIRE
    seed_pile(st, "c", influence=3)
    with pytest.raises(IllegalMove, match="no troops there"):
        apply_empire_turn(st, EmpireTurn(resolve="c"))


def test_insurgency_cannot_resolve_a_town_with_no_cards():
    st = state()
    st.hand = [Card(1, "dummy", 0)]
    st.towns["b"].troops = 2
    with pytest.raises(IllegalMove, match="no cards there"):
        apply_insurgency_turn(
            st, InsurgencyTurn(placements={"a": [0]}, resolve="b")
        )


def test_insurgency_may_resolve_a_town_it_seeded_this_turn():
    st = state()
    st.hand = [Card(1, "influence", 1), Card(2, "influence", 1)]
    st.towns["a"].troops = 0
    apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0, 1]}, resolve="a"))
    assert st.towns["a"].resolved


# ---------------------------------------------------------------------------
# Placement (Decision 6)
# ---------------------------------------------------------------------------

def test_entire_hand_must_be_placed():
    st = state()
    st.hand = [Card(1, "influence", 1), Card(2, "dummy", 0)]
    with pytest.raises(IllegalMove, match="entire hand"):
        apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0]}))


def test_cards_cannot_be_placed_in_a_resolved_town():
    st = state()
    st.towns["a"].troops = 1
    resolve_town(st, "a", Side.EMPIRE)
    st.hand = [Card(1, "influence", 1)]
    with pytest.raises(IllegalMove, match="resolved"):
        apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0]}))


def test_placed_cards_land_on_top_of_the_pile():
    """Decision 8: new cards go on top, so the Empire reads them first."""
    st = state()
    seed_pile(st, "a", dummies=2)
    st.hand = [Card(99, "influence", 1)]
    apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0]}))
    assert st.towns["a"].pile[0].uid == 99


# ---------------------------------------------------------------------------
# Peeking (Decision 8)
# ---------------------------------------------------------------------------

def test_a_stationary_troop_reads_one_card_per_turn_and_cycles_the_pile():
    st = state()
    st.towns["a"].troops = 1
    seed_pile(st, "a", influence=3)
    uids = [c.uid for c in st.towns["a"].pile]

    for expected_known in (1, 2, 3):
        st.to_move = Side.EMPIRE
        apply_empire_turn(st, EmpireTurn())
        assert len(st.empire_known_uids) == expected_known

    assert set(uids) == st.empire_known_uids
    # A full cycle returns the pile to its original order.
    assert [c.uid for c in st.towns["a"].pile] == uids


def test_peeks_stack_across_stationary_troops():
    st = state()
    st.towns["a"].troops = 3
    seed_pile(st, "a", influence=5)
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn())
    assert len(st.empire_known_uids) == 3


def test_a_troop_that_moved_does_not_peek():
    st = state()
    st.towns["a"].troops = 1
    seed_pile(st, "b", influence=3)
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn(moves=[("a", "b", 1)]))
    assert st.empire_known_uids == set()

    # ...but it reads the pile from the following turn onward.
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn())
    assert len(st.empire_known_uids) == 1


def test_the_empire_reads_the_newest_card_first():
    st = state()
    st.towns["a"].troops = 1
    seed_pile(st, "a", dummies=3)
    st.hand = [Card(99, "influence", 1)]
    apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0]}))
    apply_empire_turn(st, EmpireTurn())
    assert 99 in st.empire_known_uids


# ---------------------------------------------------------------------------
# Generation and movement (Decisions 2 and 3)
# ---------------------------------------------------------------------------

def test_generation_requires_existing_presence():
    st = state()
    st.to_move = Side.EMPIRE
    with pytest.raises(IllegalMove, match="no Empire presence"):
        apply_empire_turn(st, EmpireTurn(generate_at="c"))


def test_resolution_spends_the_troops_committed_to_it():
    """Decision 3: commitment costs something, which is what makes waiting risky."""
    st = state()
    st.towns["a"].troops = 2
    resolve_town(st, "a", Side.EMPIRE)
    assert st.towns["a"].troops == 0


def test_troop_consumption_can_be_disabled_for_experiments():
    st = state(consume_troops=False)
    st.towns["a"].troops = 2
    resolve_town(st, "a", Side.EMPIRE)
    assert st.towns["a"].troops == 2   # the counterfactual in Decision 3


def test_resolved_towns_do_not_anchor_generation():
    st = state()
    st.towns["a"].troops = 1
    st.towns["b"].troops = 1
    resolve_town(st, "a", Side.EMPIRE)
    assert "a" not in legal_generation_towns(st)
    assert "b" in legal_generation_towns(st)


def test_generation_falls_back_when_the_empire_is_swept_off_the_board():
    """Without this, spending your last troops ends the game early."""
    st = state()
    st.towns["a"].troops = 1
    resolve_town(st, "a", Side.EMPIRE)
    assert st.total_troops() == 0

    assert "a" not in legal_generation_towns(st)        # resolved
    assert set(legal_generation_towns(st)) == {"b", "c"}  # anywhere still live

    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn(generate_at="c"))
    assert st.towns["c"].troops == 1


def test_generation_normally_requires_standing_somewhere():
    st = state()
    st.towns["a"].troops = 1
    st.to_move = Side.EMPIRE
    with pytest.raises(IllegalMove, match="no Empire presence"):
        apply_empire_turn(st, EmpireTurn(generate_at="c"))


def test_movement_must_follow_an_edge():
    st = state()
    st.towns["a"].troops = 1
    st.to_move = Side.EMPIRE
    with pytest.raises(IllegalMove, match="not adjacent"):
        apply_empire_turn(st, EmpireTurn(moves=[("a", "c", 1)]))


def test_cannot_move_more_troops_than_are_present():
    st = state()
    st.towns["a"].troops = 1
    st.to_move = Side.EMPIRE
    with pytest.raises(IllegalMove, match="tried to move"):
        apply_empire_turn(st, EmpireTurn(moves=[("a", "b", 2)]))


def test_resolved_towns_are_passable_terrain():
    """Resolved towns are pacified, not walls: troops move in and out freely."""
    st = state()
    resolve_town(st, "b", Side.EMPIRE)
    st.towns["a"].troops = 1
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn(moves=[("a", "b", 1)]))
    assert st.towns["b"].troops == 1


def test_movement_is_simultaneous():
    """Two garrisons can swap places in one turn."""
    st = state(map=line_map(2))
    st.towns["a"].troops = 1
    st.towns["b"].troops = 1
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn(moves=[("a", "b", 1), ("b", "a", 1)]))
    assert st.towns["a"].troops == 1 and st.towns["b"].troops == 1


# ---------------------------------------------------------------------------
# The clock (Decision 1)
# ---------------------------------------------------------------------------

def test_deck_exhaustion_ends_the_game_and_resolves_everything_at_once():
    st = state()
    st.deck = []
    st.hand = []
    prepare_turn(st)
    assert st.game_over
    assert all(t.resolved for t in st.towns.values())


def test_unresolved_towns_are_deferred_not_safe():
    """Refusing to resolve does not protect a town; it only cedes the timing."""
    st = state()
    st.towns["b"].troops = 2         # strength 6
    seed_pile(st, "b", influence=9)  # Insurgency would win this
    st.deck, st.hand = [], []
    prepare_turn(st)
    assert st.towns["b"].winner is Side.INSURGENCY
    assert st.scores[Side.INSURGENCY] == 6


def test_game_ends_when_every_town_is_resolved_even_with_deck_left():
    st = state()
    for town_id in st.towns:
        st.towns[town_id].resolved = True
    prepare_turn(st)
    assert st.game_over


def test_a_full_game_terminates_and_resolves_every_town():
    from .bots import HeuristicEmpire, HeuristicInsurgency
    from .engine import play_game

    real = load_scenario("baseline")
    for seed in range(20):
        rng = random.Random(seed)
        st = play_game(real, HeuristicEmpire(rng), HeuristicInsurgency(rng), rng)
        assert st.game_over
        assert all(t.resolved for t in st.towns.values())
        assert winner(st) in (Side.EMPIRE, Side.INSURGENCY, None)


def test_scoring_conserves_what_was_actually_committed():
    """Total points must equal the sum of losing-side commitments, nothing more."""
    from .bots import RandomEmpire, RandomInsurgency
    from .engine import play_game

    real = load_scenario("baseline")
    rng = random.Random(7)
    st = play_game(real, RandomEmpire(rng), RandomInsurgency(rng), rng)

    expected = 0
    for town in st.towns.values():
        if town.winner is Side.EMPIRE:
            expected += town.resolved_influence
        else:
            expected += town.resolved_strength
    assert st.scores[Side.EMPIRE] + st.scores[Side.INSURGENCY] == expected
