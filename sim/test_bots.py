"""Tests for the two bots that play their side the way it is played well.

GlobEmpire plays for its supply network; MistBot plays the geography of the
map. Both are pinned case by case below.

Each test names the rule it pins down. The rules themselves are in the class
docstring in `bots.py` and in the project notes; if one of these fails after a
change to the bot, one of those needs updating too.

Run with:  sim/.venv/bin/python -m pytest sim -q
"""

from __future__ import annotations

import random

from .bots import Glob2Empire, GlobEmpire, HeuristicInsurgency, Mist2Insurgency, MistBot
from .config import CardType, GameMap, Scenario, TownDef, Unit
from .engine import (
    Card,
    Side,
    apply_empire_turn,
    apply_insurgency_turn,
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


# ---------------------------------------------------------------------------
# Glob2Empire: isolated garrisons get a much tighter leash (issue #18)
#
# These use a line (a-b-c-d) rather than the fan: the fan's hub touches every
# leaf, so an occupied leaf is never disconnected from an occupied hub. A
# line lets a garrison at "d" be a genuinely separate component from one at
# "a" whenever "b" and "c" stand empty between them.
# ---------------------------------------------------------------------------

def glob2_turn(st, **kwargs):
    return Glob2Empire(random.Random(0), **kwargs).choose(st)


def test_glob2_withdraws_an_isolated_garrison_the_base_bot_leaves_standing():
    """A deficit of 1 is well inside GlobEmpire's margin of 5, but there is
    nobody next to an outpost to send it help — so Glob2 reacts at once."""
    st = board(map=line_map())
    st.towns["a"].troops = 6  # the main army: the home component
    st.towns["d"].troops = 1  # a lone outpost, unreachable from "a" in one hop
    seed(st, "d", 2)  # worst_case 2, deficit 1

    base_plan = turn(st, retreat_margin=5)
    assert all(src != "d" for src, _, _ in base_plan.moves)

    plan = glob2_turn(st, retreat_margin=5)
    assert ("d", "c", 1) in plan.moves


def test_glob2_reinforces_an_isolated_garrison_the_base_bot_ignores():
    """The outpost has a small ally next door that can save it — Glob2 sends
    the help; GlobEmpire never notices the trouble at deficit 1."""
    st = board(map=line_map())
    st.towns["a"].troops = 6  # home
    st.towns["c"].troops = 3  # a small, separate garrison next to "d"
    st.towns["d"].troops = 1
    seed(st, "d", 2)  # worst_case 2, deficit 1

    base_plan = turn(st, retreat_margin=5)
    assert all(dst != "d" or src != "c" for src, dst, _ in base_plan.moves)

    plan = glob2_turn(st, retreat_margin=5)
    assert ("c", "d", 1) in plan.moves


def test_glob2_still_tolerates_a_bluff_sized_threat_inside_the_main_army():
    """One component, one army: the isolated margin never applies, and Glob2
    matches the base bot exactly."""
    st = board()
    st.towns["b"].troops = 3
    seed(st, "b", 5)  # deficit 2, well under either margin
    assert all(src != "b" for src, _, _ in glob2_turn(st).moves)


# ---------------------------------------------------------------------------
# MistBot: the Insurgency that plays the map
#
# Each test names one rule from the class docstring in bots.py. The bot takes
# an rng and never uses it, so every case below is exact rather than
# statistical — a different answer here is a different bot, not a seed.
# ---------------------------------------------------------------------------

def line_map(supply: int = 10, producers: tuple[str, ...] = ()) -> GameMap:
    """Four towns in a row: a—b—c—d.

    The fan asks which neighbour; a line asks how far, which is the question
    the rebel bot is built around — how much of the Empire can reach a town in
    one move, and how much of it can reach the town next door.
    """
    names = ("a", "b", "c", "d")
    towns = tuple(
        TownDef(id=n, label=n.upper(), x=i, y=0, supply=supply,
                production=1 if n in producers else 0)
        for i, n in enumerate(names)
    )
    return GameMap(id="line", label="Line", towns=towns,
                   edges=(("a", "b"), ("b", "c"), ("c", "d")))


def rebel_board(hand: tuple[int, ...] = (1, 1, 1), **overrides):
    """An empty board with the Insurgency to move and a hand of given values."""
    st = new_game(scenario(**overrides), random.Random(0))
    for town in st.towns.values():
        town.troops = 0
    st.to_move = Side.INSURGENCY
    st.hand = [
        Card(uid=500 + i, type_id=f"presence{v}", presence=v)
        for i, v in enumerate(hand)
    ]
    return st


def mist(st):
    return MistBot(random.Random(0)).choose(st)


def placed_in(plan, town_id: str) -> list[int]:
    return plan.placements.get(town_id, [])


# -- resolution: everything already won -------------------------------------

def test_mist_cashes_a_town_it_has_already_beaten():
    st = rebel_board()
    st.towns["a"].troops = 1
    seed(st, "a", 2)
    assert mist(st).resolve == "a"


def test_mist_leaves_a_tie_alone_because_the_empire_wins_ties():
    """Two cards against two troops is a loss, not a win, so it is not cashed."""
    st = rebel_board()
    st.towns["a"].troops = 2
    seed(st, "a", 2)
    assert mist(st).resolve is None


def test_mist_takes_the_richest_win_first():
    """Only one resolution a turn, and the score is the garrison overcome."""
    st = rebel_board()
    for town_id, troops in (("b", 1), ("c", 3), ("d", 2)):
        st.towns[town_id].troops = troops
        seed(st, town_id, troops + 1)
    assert mist(st).resolve == "c"


def test_mist_breaks_a_score_tie_toward_the_busiest_neighbourhood():
    """Equal prizes, so take the one the Empire is likeliest to reinforce.

    B touches two occupied towns and C touches one, so B is the one that will
    not still be winnable next turn.
    """
    st = rebel_board(map=line_map())
    st.towns["a"].troops = 1
    for town_id in ("b", "c"):
        st.towns[town_id].troops = 1
        seed(st, town_id, 2)
    assert mist(st).resolve == "b"


def test_mist_cashes_an_empty_town_for_nothing():
    """Worth no points and worth taking: the Empire can never supply it again."""
    st = rebel_board()
    seed(st, "b", 1)
    assert mist(st).resolve == "b"


def test_mist_does_not_place_into_the_town_it_just_resolved():
    st = rebel_board()
    st.towns["a"].troops = 1
    seed(st, "a", 2)
    plan = mist(st)
    assert plan.resolve == "a"
    assert placed_in(plan, "a") == []


# -- taking the lead --------------------------------------------------------

def test_mist_clears_the_garrison_by_exactly_one_with_the_fewest_cards():
    """Two troops need three presence; a single 3 does it and two 1s are saved."""
    st = rebel_board(hand=(3, 1, 1))
    st.towns["a"].troops = 2
    plan = mist(st)
    assert placed_in(plan, "a") == [0]


def test_mist_prefers_the_garrison_with_the_fewest_troops_in_reach():
    """Equal garrisons; C has one troop next door and B has four."""
    st = rebel_board(hand=(1, 1), map=line_map())
    st.towns["a"].troops = 3
    st.towns["b"].troops = 1
    st.towns["c"].troops = 1
    plan = mist(st)
    assert placed_in(plan, "c") == [0, 1]
    assert placed_in(plan, "b") == []


def test_mist_leads_in_a_second_town_when_the_hand_stretches():
    st = rebel_board(hand=(1, 1, 1, 1), map=line_map())
    st.towns["b"].troops = 1
    st.towns["c"].troops = 1
    plan = mist(st)
    assert len(placed_in(plan, "b")) == 2
    assert len(placed_in(plan, "c")) == 2


def test_mist_does_not_half_commit_to_a_lead_it_cannot_afford():
    """Half a lead is a donation: the Empire scores every card in a town it wins."""
    st = rebel_board(hand=(1, 1))
    st.towns["a"].troops = 5
    plan = mist(st)
    assert placed_in(plan, "a") == []
    assert sum(len(ix) for ix in plan.placements.values()) == 2


# -- real cards with nothing to flip ----------------------------------------

def test_mist_seeds_an_empty_town_beside_a_garrison_that_can_spare_a_troop():
    """A lone troop marching out abandons its town, so it is not really a
    neighbour; two is the smallest garrison that can come and make a fight."""
    st = rebel_board(hand=(1,), map=line_map())
    st.towns["a"].troops = 3
    st.towns["c"].troops = 1
    plan = mist(st)
    assert placed_in(plan, "b") == [0]
    assert placed_in(plan, "d") == []


# -- bluffs -----------------------------------------------------------------

def test_mist_spreads_bluffs_one_empty_town_at_a_time():
    """A second bluff on the same town says nothing the first did not."""
    st = rebel_board(hand=(0, 0, 0))
    st.towns["a"].troops = 2
    plan = mist(st)
    assert placed_in(plan, "b") == [0]
    assert placed_in(plan, "c") == [1]
    assert placed_in(plan, "d") == [2]


def test_mist_never_bluffs_an_empty_town_no_troops_can_reach():
    """Nobody will ever walk into it, so the bluff has no audience."""
    st = rebel_board(hand=(0, 0), map=line_map())
    st.towns["a"].troops = 2
    plan = mist(st)
    assert placed_in(plan, "c") == []
    assert placed_in(plan, "d") == []


def test_mist_bluffs_the_closest_thing_to_a_tie_once_the_empty_towns_are_gone():
    """Every town is garrisoned, so there is no empty ground to seed.

    B is level with its garrison and the others are one or two clear, so B is
    the only pile a card can change the Empire's reading of.
    """
    st = rebel_board(hand=(0,), map=line_map())
    for town_id, troops in (("a", 2), ("b", 1), ("c", 2), ("d", 1)):
        st.towns[town_id].troops = troops
    seed(st, "b", 1)
    plan = mist(st)
    assert placed_in(plan, "b") == [0]


def test_mist_falls_back_to_the_nearest_town_when_nothing_is_in_reach():
    """Forced: the whole hand must go out (Decision 6) even with no audience."""
    st = rebel_board(hand=(0,), map=line_map())
    st.towns["a"].troops = 2
    st.towns["a"].resolved = True
    st.towns["b"].resolved = True
    plan = mist(st)
    assert placed_in(plan, "c") == [0]


# -- whole games ------------------------------------------------------------

def test_mist_places_its_entire_hand_every_turn():
    real = load_scenario("baseline")
    rng = random.Random(3)
    st = new_game(real, rng)
    bot = MistBot(rng)
    for _ in range(6):
        st.to_move = Side.INSURGENCY
        plan = bot.choose(st)
        placed = sorted(i for ix in plan.placements.values() for i in ix)
        assert placed == list(range(len(st.hand)))
        apply_insurgency_turn(st, plan)
        st.hand = [Card(uid=900, type_id="influence1", presence=1)
                   for _ in range(real.hand_size)]


def test_mist_plays_legal_games_on_the_real_scenario():
    real = load_scenario("baseline")
    for s in range(20):
        rng = random.Random(s)
        st = play_game(real, GlobEmpire(rng), MistBot(rng), rng)
        assert st.game_over
        for town in st.towns.values():
            assert town.resolved or town_is_uncontested(town)


def test_mist_beats_the_empire_bot_that_beats_the_heuristic_rebels():
    """The reason it exists. GlobEmpire takes about 64% against the heuristic
    Insurgency and should not manage that here."""
    real = load_scenario("baseline")
    wins = 0
    for s in range(50):
        rng = random.Random(s)
        st = play_game(real, GlobEmpire(rng), MistBot(rng), rng)
        wins += st.scores[Side.INSURGENCY] > st.scores[Side.EMPIRE]
    assert wins > 25


# ---------------------------------------------------------------------------
# Mist2Insurgency: lead with the richest garrison, not the safest one
# (issue #18)
# ---------------------------------------------------------------------------

def mist2(st):
    return Mist2Insurgency(random.Random(0)).choose(st)


def test_mist2_leads_the_richer_garrison_even_though_it_is_better_defended():
    """B is the bigger prize but sits next to a 5-troop garrison at A; D is
    poorer but has nothing next door. Neither hand affords A at all.

    MistBot picks D — fewer troops in reach. Mist2 picks B — the richer
    target — because clearing it by one costs the rebels no more than
    clearing D by one."""
    st = rebel_board(hand=(2, 2), map=line_map())
    st.towns["a"].troops = 5   # too expensive for either bot to consider
    st.towns["b"].troops = 3   # richer, and defended by A next door
    st.towns["d"].troops = 1   # poorer, and undefended

    base_plan = mist(st)
    assert placed_in(base_plan, "d") == [0]
    assert placed_in(base_plan, "b") == []

    plan = mist2(st)
    assert placed_in(plan, "b") == [0, 1]
    assert placed_in(plan, "d") == []


def test_mist2_still_breaks_a_tie_between_equally_rich_targets_by_safety():
    """Equal garrisons: the fewest-troops-in-reach tie-break survives,
    unchanged from MistBot, when richness alone cannot decide."""
    st = rebel_board(hand=(1, 1), map=line_map())
    st.towns["a"].troops = 3
    st.towns["b"].troops = 1
    st.towns["c"].troops = 1
    plan = mist2(st)
    assert placed_in(plan, "c") == [0, 1]
    assert placed_in(plan, "b") == []


def test_mist2_plays_legal_games_on_the_real_scenario():
    real = load_scenario("baseline")
    for s in range(20):
        rng = random.Random(s)
        st = play_game(real, GlobEmpire(rng), Mist2Insurgency(rng), rng)
        assert st.game_over
        for town in st.towns.values():
            assert town.resolved or town_is_uncontested(town)


def test_glob2_and_mist2_play_legal_games_together():
    real = load_scenario("baseline")
    for s in range(20):
        rng = random.Random(s)
        st = play_game(real, Glob2Empire(rng), Mist2Insurgency(rng), rng)
        assert st.game_over
        for town in st.towns.values():
            assert town.resolved or town_is_uncontested(town)
