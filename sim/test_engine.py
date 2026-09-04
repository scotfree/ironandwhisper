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
    ceiling,
    component_of,
    empire_components,
    headroom,
    production_capacity,
    production_sites,
    troops_in,
    new_game,
    prepare_turn,
    resolve_town,
    winner,
)

INFANTRY = Unit(id="infantry", label="Imperial Infantry", strength=3, movement=1, peek=1)
CARD_TYPES = {
    "influence0": CardType(id="influence0", label="Influence 0", influence=0),
    "influence1": CardType(id="influence1", label="Influence 1", influence=1),
    "influence2": CardType(id="influence2", label="Influence 2", influence=2),
    "influence3": CardType(id="influence3", label="Influence 3", influence=3),
}


def line_map(n: int = 3, supply: int = 2, producers: tuple[str, ...] = ("a",)) -> GameMap:
    """A path graph: a-b-c-..., the simplest thing with real adjacency."""
    names = [chr(ord("a") + i) for i in range(n)]
    towns = tuple(
        TownDef(id=x, label=x.upper(), x=i, y=0, supply=supply,
                production=1 if x in producers else 0)
        for i, x in enumerate(names)
    )
    edges = tuple((names[i], names[i + 1]) for i in range(n - 1))
    return GameMap(id="line", label="Line", towns=towns, edges=edges)


def scenario(**overrides) -> Scenario:
    fields = dict(
        id="test", label="Test", map=line_map(), unit=INFANTRY,
        card_types=CARD_TYPES, hand_size=2,
        deck={"influence1": 4, "influence0": 4},
        supply_per_troop=1, production_cost=1,
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
        st.towns[town_id].pile.insert(0, Card(uid=uid, type_id="influence1", influence=1))
        uid += 1
    for _ in range(dummies):
        st.towns[town_id].pile.insert(0, Card(uid=uid, type_id="influence0", influence=0))
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


def test_resolution_turns_the_whole_town_face_up():
    """Decision 9: what was in a town is public once it has been fought over."""
    st = state()
    st.towns["a"].troops = 0            # so the Insurgency takes it and the cards stay
    seed_pile(st, "a", influence=2, dummies=2)
    uids = {c.uid for c in st.towns["a"].pile}

    resolve_town(st, "a", Side.INSURGENCY)

    assert not st.towns["a"].pile, "nothing is left face down"
    assert {c.uid for c in st.towns["a"].revealed} == uids
    assert st.towns["a"].resolved_influence == 2, "and the total is on the record"


def test_the_empire_takes_the_cards_it_beats_off_the_board():
    st = state()
    st.towns["a"].troops = 2
    seed_pile(st, "a", influence=2, dummies=2)

    resolve_town(st, "a", Side.EMPIRE)

    assert st.towns["a"].card_count == 0, "captured cards leave"
    assert st.towns["a"].resolved_influence == 2, "but the record survives them"


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
    st.hand = [Card(1, "influence0", 0)]
    st.towns["b"].troops = 2
    with pytest.raises(IllegalMove, match="no cards there"):
        apply_insurgency_turn(
            st, InsurgencyTurn(placements={"a": [0]}, resolve="b")
        )


def test_insurgency_may_resolve_a_town_it_seeded_this_turn():
    st = state()
    st.hand = [Card(1, "influence1", 1), Card(2, "influence1", 1)]
    st.towns["a"].troops = 0
    apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0, 1]}, resolve="a"))
    assert st.towns["a"].resolved


# ---------------------------------------------------------------------------
# Placement (Decision 6)
# ---------------------------------------------------------------------------

def test_entire_hand_must_be_placed():
    st = state()
    st.hand = [Card(1, "influence1", 1), Card(2, "influence0", 0)]
    with pytest.raises(IllegalMove, match="entire hand"):
        apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0]}))


def test_cards_cannot_be_placed_in_a_resolved_town():
    st = state()
    st.towns["a"].troops = 1
    resolve_town(st, "a", Side.EMPIRE)
    st.hand = [Card(1, "influence1", 1)]
    with pytest.raises(IllegalMove, match="resolved"):
        apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0]}))


def test_placed_cards_land_on_top_of_the_pile():
    """Decision 8: new cards go on top, so the Empire reads them first."""
    st = state()
    seed_pile(st, "a", dummies=2)
    st.hand = [Card(99, "influence1", 1)]
    apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0]}))
    assert st.towns["a"].pile[0].uid == 99


# ---------------------------------------------------------------------------
# Peeking (Decision 8)
# ---------------------------------------------------------------------------

def test_a_stationary_troop_reads_one_card_per_turn():
    st = state()
    st.towns["a"].troops = 1
    seed_pile(st, "a", influence=3)
    uids = [c.uid for c in st.towns["a"].pile]

    for expected_known in (1, 2, 3):
        st.to_move = Side.EMPIRE
        apply_empire_turn(st, EmpireTurn())
        assert len(st.towns["a"].revealed) == expected_known

    # The pile holds only what is still unknown, so it empties as it is read.
    assert [c.uid for c in st.towns["a"].revealed] == uids
    assert st.towns["a"].pile == []


def test_a_look_is_never_spent_on_a_card_already_face_up():
    """The point of setting cards aside: the pile holds only unknowns."""
    st = state()
    st.towns["a"].troops = 1
    seed_pile(st, "a", influence=2)

    for _ in range(4):
        st.to_move = Side.EMPIRE
        apply_empire_turn(st, EmpireTurn())

    # Two cards, read once each. The spare turns had nothing left to find, and
    # crucially did not re-read what was already known.
    assert len(st.towns["a"].revealed) == 2

    # A new card lands on top of an empty face-down pile and is read next turn.
    st.hand = [Card(99, "influence1", 1)]
    st.to_move = Side.INSURGENCY
    apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0]}))
    apply_empire_turn(st, EmpireTurn())
    assert 99 in {c.uid for c in st.towns["a"].revealed}


def test_revealed_cards_still_count_at_resolution():
    """Face up is not out of play: the town is worth its whole contents."""
    st = state()
    st.towns["a"].troops = 1
    seed_pile(st, "a", influence=4)
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn())
    assert len(st.towns["a"].revealed) == 1, "one card is face up"
    assert len(st.towns["a"].pile) == 3

    # 4 influence against 3 strength. If the face-up card had stopped counting
    # it would be 3 against 3, and the Empire would take it on the tie.
    winner = resolve_town(st, "a", Side.INSURGENCY)
    assert st.towns["a"].resolved_influence == 4
    assert winner is Side.INSURGENCY


def test_peeks_stack_across_stationary_troops():
    st = state()
    st.towns["a"].troops = 3
    seed_pile(st, "a", influence=5)
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn())
    assert len(st.towns["a"].revealed) == 3
    assert len(st.towns["a"].pile) == 2


def test_a_troop_that_moved_does_not_peek():
    st = state()
    st.towns["a"].troops = 1
    seed_pile(st, "b", influence=3)
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn(moves=[("a", "b", 1)]))
    assert st.towns["b"].revealed == []

    # ...but it reads the pile from the following turn onward.
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn())
    assert len(st.towns["b"].revealed) == 1


def test_the_empire_reads_the_newest_card_first():
    st = state()
    st.towns["a"].troops = 1
    seed_pile(st, "a", dummies=3)
    st.hand = [Card(99, "influence1", 1)]
    apply_insurgency_turn(st, InsurgencyTurn(placements={"a": [0]}))
    apply_empire_turn(st, EmpireTurn())
    assert [c.uid for c in st.towns["a"].revealed] == [99]


# ---------------------------------------------------------------------------
# Supply, production and attrition (Decision 2)
# ---------------------------------------------------------------------------

def test_a_network_is_the_towns_the_empire_stands_in():
    st = state()
    st.towns["a"].troops = 1
    st.towns["b"].troops = 1
    assert empire_components(st) == [{"a", "b"}]

    # c is held but not adjacent to anything held, since b is empty.
    st.towns["b"].troops = 0
    st.towns["c"].troops = 1
    assert empire_components(st) == [{"a"}, {"c"}]


def test_a_resolved_town_still_carries_supply():
    """A town the Empire won and still garrisons is part of the network."""
    st = state()
    st.towns["a"].troops = 2
    st.towns["b"].troops = 1
    resolve_town(st, "a", Side.EMPIRE)

    assert st.towns["a"].troops == 2, "the winner keeps its troops"
    assert empire_components(st) == [{"a", "b"}]
    assert ceiling(st, {"a", "b"}) == 4


def test_the_ceiling_is_the_networks_supply():
    st = state()
    st.towns["a"].troops = 1
    st.towns["b"].troops = 1
    assert ceiling(st, component_of(st, "a")) == 4   # two towns at supply 2
    assert troops_in(st, component_of(st, "a")) == 2
    assert headroom(st, "a") == 2


def test_building_needs_presence_production_and_supply():
    st = state()
    st.towns["a"].troops = 1
    st.to_move = Side.EMPIRE

    # c has no garrison, so it is not the Empire's to build in.
    with pytest.raises(IllegalMove, match="no Empire presence"):
        apply_empire_turn(st, EmpireTurn(produce={"c": 1}))

    # b is held but produces nothing.
    st.towns["b"].troops = 1
    assert production_sites(st) == ["a"]
    assert production_capacity(st, "b") == 0


def test_building_stops_at_the_ceiling():
    st = state(map=line_map(supply=1))
    st.towns["a"].troops = 1
    st.to_move = Side.EMPIRE

    # One town at supply 1 supports exactly the troop already standing there.
    assert headroom(st, "a") == 0
    with pytest.raises(IllegalMove, match="supply supports"):
        apply_empire_turn(st, EmpireTurn(produce={"a": 1}))


def test_a_wider_network_supports_more_troops():
    st = state(map=line_map(supply=1))
    st.towns["a"].troops = 1
    st.towns["b"].troops = 1
    st.to_move = Side.EMPIRE

    assert headroom(st, "a") == 0
    st.towns["c"].troops = 1          # a third town, a third point of supply
    assert headroom(st, "a") == 0     # ...already spent on the troop holding it

    st.towns["c"].troops = 0
    st.towns["b"].troops = 2          # two towns, supply 2, three troops standing
    assert troops_in(st, component_of(st, "a")) == 3
    assert ceiling(st, component_of(st, "a")) == 2


def test_troops_a_network_cannot_supply_starve_and_score():
    """Cutting a supply line takes troops off the board, so it pays like a fight."""
    st = state(map=line_map(supply=1))
    st.towns["a"].troops = 3          # one town, supply 1, three troops
    st.to_move = Side.EMPIRE

    apply_empire_turn(st, EmpireTurn())

    assert st.towns["a"].troops == 1, "starved down to what supply can hold"
    assert st.scores[Side.INSURGENCY] == 2 * INFANTRY.strength, (
        "the Insurgency scores every Empire troop that leaves the board"
    )


def test_attrition_takes_the_empires_choice_first():
    st = state(map=line_map(supply=1))
    st.towns["a"].troops = 2
    st.towns["b"].troops = 2
    st.to_move = Side.EMPIRE

    # Four troops, two supply: two starve, and the Empire says where from.
    apply_empire_turn(st, EmpireTurn(disband={"b": 2}))

    assert st.towns["a"].troops == 2
    assert st.towns["b"].troops == 0


def test_severing_a_line_halves_two_ceilings_rather_than_one():
    st = state(map=line_map(5, supply=1))
    for town_id in ("a", "b", "c", "d", "e"):
        st.towns[town_id].troops = 1

    assert ceiling(st, component_of(st, "a")) == 5

    # The Insurgency takes the middle town; the line falls into two.
    st.towns["c"].troops = 0
    assert sorted(empire_components(st), key=len) == [{"a", "b"}, {"d", "e"}]
    assert ceiling(st, component_of(st, "a")) == 2
    assert ceiling(st, component_of(st, "e")) == 2


# ---------------------------------------------------------------------------
# Resolution takes the loser's commitment (Decision 3)
# ---------------------------------------------------------------------------

def test_the_empire_keeps_its_troops_when_it_wins():
    st = state()
    st.towns["a"].troops = 2
    seed_pile(st, "a", influence=1)

    resolve_town(st, "a", Side.EMPIRE)

    assert st.towns["a"].troops == 2, "the winner's commitment stays on the board"
    assert st.towns["a"].card_count == 0, "the loser's is taken and scored"
    assert st.scores[Side.EMPIRE] == 1


def test_the_empire_loses_its_troops_when_it_loses():
    st = state()
    st.towns["a"].troops = 1
    seed_pile(st, "a", influence=5)

    resolve_town(st, "a", Side.INSURGENCY)

    assert st.towns["a"].troops == 0
    assert st.towns["a"].card_count == 5, "the winner's cards stay"
    assert st.scores[Side.INSURGENCY] == INFANTRY.strength


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

    # The Empire scores only what it captures at a resolution, so its total is
    # exactly the influence it beat.
    captured = sum(
        town.resolved_influence for town in st.towns.values()
        if town.winner is Side.EMPIRE
    )
    assert st.scores[Side.EMPIRE] == captured

    # The Insurgency scores every Empire troop that left the board, which is the
    # troops it beat at resolutions plus any that starved when a line was cut.
    beaten = sum(
        town.resolved_strength for town in st.towns.values()
        if town.winner is Side.INSURGENCY
    )
    assert st.scores[Side.INSURGENCY] >= beaten
    assert (st.scores[Side.INSURGENCY] - beaten) % st.scenario.unit.strength == 0, (
        "the excess is whole troops, starved"
    )


def test_a_town_the_rebels_take_never_supplies_the_empire_again():
    """The Insurgency cannot build a network, so its victories destroy one."""
    st = state(map=line_map(supply=2, producers=("a", "b")))
    st.towns["a"].troops = 1
    st.towns["b"].troops = 1
    assert ceiling(st, component_of(st, "a")) == 4

    # The rebels take b. The Empire may march back in — b is resolved, so it can
    # never be contested again — but it will never feed the Empire again either.
    st.towns["b"].troops = 0
    seed_pile(st, "b", influence=1)
    resolve_town(st, "b", Side.INSURGENCY)
    st.towns["b"].troops = 1

    assert component_of(st, "a") == {"a", "b"}, "still a road, still garrisoned"
    assert ceiling(st, component_of(st, "a")) == 2, "but b feeds nothing"


def test_a_town_the_rebels_take_never_builds_for_the_empire_again():
    st = state(map=line_map(supply=2, producers=("a", "b")))
    st.towns["b"].troops = 1
    assert production_capacity(st, "b") == 1

    seed_pile(st, "b", influence=5)
    resolve_town(st, "b", Side.INSURGENCY)
    st.towns["b"].troops = 1

    assert production_capacity(st, "b") == 0
    assert "b" not in production_sites(st)
