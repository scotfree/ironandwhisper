"""Tests for GlobEmpire, the bot that plays the way the game is played well.

Each test names the rule it pins down. The rules themselves are in the class
docstring in `bots.py` and in the project notes; if one of these fails after a
change to the bot, one of those needs updating too.

Run with:  sim/.venv/bin/python -m pytest sim -q
"""

from __future__ import annotations

import random

from .bots import GlobEmpire, HeuristicInsurgency
from .config import CardType, GameMap, Scenario, TownDef, Unit
from .engine import (
    Card,
    Side,
    apply_empire_turn,
    ceiling,
    component_of,
    empire_components,
    new_game,
    play_game,
    town_is_uncontested,
    troops_in,
)
from .config import load_scenario

FOOT = Unit(id="foot", label="Foot", presence=1, movement=1, peek=1)
CARD_TYPES = {
    "influence0": CardType(id="influence0", label="Agent +0", presence=0),
    "influence1": CardType(id="influence1", label="Agent +1", presence=1),
}


def fan_map(supply: int = 10, producers: tuple[str, ...] = ("a",)) -> GameMap:
    """A star: `a` in the middle, b, c and d hanging off it.

    Everything the bot decides is about which *neighbour* to prefer, so one
    town with three choices is the smallest board that asks the question.
    """
    names = ("a", "b", "c", "d")
    towns = tuple(
        TownDef(id=n, label=n.upper(), x=i, y=0, supply=supply,
                production=1 if n in producers else 0)
        for i, n in enumerate(names)
    )
    return GameMap(id="fan", label="Fan", towns=towns,
                   edges=(("a", "b"), ("a", "c"), ("a", "d")))


def scenario(**overrides) -> Scenario:
    fields = dict(
        id="test", label="Test", map=fan_map(), unit=FOOT,
        card_types=CARD_TYPES, hand_size=2,
        deck={"influence1": 8, "influence0": 8},
        supply_per_troop=1, production_cost=1,
        empire_start={"a": 1}, first_player=Side.INSURGENCY,
        empire_wins_ties=True,
    )
    fields.update(overrides)
    return Scenario(**fields)


def board(**overrides):
    st = new_game(scenario(**overrides), random.Random(0))
    for town in st.towns.values():
        town.troops = 0
    st.to_move = Side.EMPIRE
    return st


def seed(st, town_id: str, count: int, presence: int = 1) -> None:
    """Put `count` face-down cards into a pile. The bot may not look at them."""
    for i in range(count):
        st.towns[town_id].pile.insert(
            0, Card(uid=1000 + i, type_id=f"presence{presence}", presence=presence)
        )


def turn(st, **kwargs):
    return GlobEmpire(random.Random(0), **kwargs).choose(st)


# ---------------------------------------------------------------------------
# Resolution: certain wins only
# ---------------------------------------------------------------------------

def test_a_pile_it_could_lose_to_is_left_alone():
    """Certainty is measured against the worst the pile could be, not the mean.

    Three face-down cards could be worth three, so two troops are not enough —
    even though the deck is half bluffs and two is the likelier value.
    """
    st = board()
    st.towns["a"].troops = 2
    seed(st, "a", 3)
    assert turn(st).resolve is None


def test_a_pile_it_cannot_lose_to_is_taken():
    st = board()
    st.towns["a"].troops = 3
    seed(st, "a", 3)
    assert turn(st).resolve == "a"


def test_a_tie_counts_as_certain_because_the_empire_wins_ties():
    st = board()
    st.towns["a"].troops = 2
    seed(st, "a", 2)
    assert turn(st).resolve == "a"


def test_a_card_it_has_already_seen_counts_at_its_real_value():
    """A look moves a card face up, so peeked cards sharpen the bound for free.

    Two face-down cards would need two troops. Turn one of them over and find a
    bluff, and one troop is now certain.
    """
    st = board()
    st.towns["a"].troops = 1
    seed(st, "a", 2)
    st.towns["a"].revealed.append(st.towns["a"].pile.pop(0))
    st.towns["a"].revealed[0] = Card(uid=9, type_id="influence0", presence=0)
    assert turn(st).resolve == "a"


def test_the_richest_certain_win_is_the_one_taken():
    st = board()
    for town_id, cards in (("b", 1), ("c", 3), ("d", 2)):
        st.towns[town_id].troops = 3
        seed(st, town_id, cards)
    assert turn(st).resolve == "c"


def test_an_empty_town_is_resolved_when_there_is_nothing_richer():
    """Worth no points, worth taking: it locks the ground and its supply."""
    st = board()
    st.towns["a"].troops = 1
    assert turn(st).resolve == "a"


def test_it_marches_out_of_the_town_it_just_resolved():
    """A certain win keeps its garrison (Decision 3), so the troops still exist.

    The other bots hold still where they are fighting because they cannot know
    how the fight went. This one has already checked, so the whole point of
    resolution-first — win the town, then keep moving — is available to it.
    """
    st = board()
    st.towns["a"].troops = 3
    seed(st, "a", 1)
    plan = turn(st)
    assert plan.resolve == "a"
    assert any(src == "a" for src, _, _ in plan.moves)
    apply_empire_turn(st, plan)  # and it is a legal turn


# ---------------------------------------------------------------------------
# Expansion: the wave
# ---------------------------------------------------------------------------

def test_it_expands_into_several_towns_at_once():
    """A three-card hand cannot contest three new towns in one reply."""
    st = board()
    st.towns["a"].troops = 4
    plan = turn(st)
    assert {dst for _, dst, _ in plan.moves} == {"b", "c", "d"}


def test_a_seeded_town_it_can_beat_is_preferred_to_an_empty_one():
    """The supply is identical and the presence is points it will collect."""
    st = board()
    st.towns["a"].troops = 3
    seed(st, "c", 2)
    plan = turn(st)
    sent = {dst: qty for _, dst, qty in plan.moves}
    assert sent["c"] == 2  # exactly enough to be certain of it, and no more
    assert sum(sent.values()) == 3  # the spare troop still expands somewhere


def test_a_town_it_cannot_be_certain_of_is_not_walked_into():
    st = board()
    st.towns["a"].troops = 2
    seed(st, "b", 5)
    assert all(dst != "b" for _, dst, _ in turn(st).moves)


def test_expansion_stops_at_the_ceiling():
    """Spreading out is only worth it while the network can still eat."""
    st = board(map=fan_map(supply=1))
    st.towns["a"].troops = 2
    plan = turn(st)
    apply_empire_turn(st, plan)
    for component in empire_components(st):
        assert troops_in(st, component) <= ceiling(st, component)


# ---------------------------------------------------------------------------
# Retreat and relief
# ---------------------------------------------------------------------------

def test_a_garrison_that_can_be_saved_is_reinforced():
    st = board()
    st.towns["b"].troops = 1
    st.towns["a"].troops = 8
    seed(st, "b", 6)
    plan = turn(st)
    assert ("a", "b", 5) in plan.moves


def test_a_garrison_that_cannot_be_saved_withdraws_entirely():
    """Half a relief column loses the whole column as well as the town."""
    st = board()
    st.towns["b"].troops = 1
    seed(st, "b", 6)
    plan = turn(st)
    assert ("b", "a", 1) in plan.moves


def test_being_behind_by_less_than_the_margin_is_not_a_retreat():
    """`worst_case` assumes every hidden card is the best in the deck, and most
    are not. Withdrawing from every pile that could beat you hands towns to
    bluffs."""
    st = board()
    st.towns["b"].troops = 3
    seed(st, "b", 5)
    assert all(src != "b" for src, _, _ in turn(st).moves)


# ---------------------------------------------------------------------------
# Production
# ---------------------------------------------------------------------------

def test_it_builds_past_the_ceiling_the_march_is_about_to_raise():
    """The network has no headroom until the troops move, and the troops move."""
    st = board(map=fan_map(supply=2))
    st.towns["a"].troops = 2
    plan = turn(st)
    assert plan.produce.get("a", 0) >= 1
    apply_empire_turn(st, plan)
    for component in empire_components(st):
        assert troops_in(st, component) <= ceiling(st, component)


# ---------------------------------------------------------------------------
# Whole games
# ---------------------------------------------------------------------------

def test_it_plays_legal_games_on_the_real_scenario():
    real = load_scenario("baseline")
    for s in range(20):
        rng = random.Random(s)
        st = play_game(real, GlobEmpire(rng), HeuristicInsurgency(rng), rng)
        assert st.game_over
        for town in st.towns.values():
            assert town.resolved or town_is_uncontested(town)


def test_it_beats_the_heuristic_insurgency_more_often_than_not():
    """The reason the bot exists. The old heuristic Empire wins about 0.5%."""
    real = load_scenario("baseline")
    wins = 0
    for s in range(100):
        rng = random.Random(s)
        st = play_game(real, GlobEmpire(rng), HeuristicInsurgency(rng), rng)
        wins += st.scores[Side.EMPIRE] > st.scores[Side.INSURGENCY]
    assert wins > 50
