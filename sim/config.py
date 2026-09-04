"""Loads the shared JSON config that both this simulator and the PHP read.

Nothing here is simulator-specific. If you change the shape of these files,
the PHP implementation has to change with them.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from functools import cached_property
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DATA_DIR = PROJECT_ROOT / "data"
MAPS_DIR = PROJECT_ROOT / "maps"
SCENARIOS_DIR = PROJECT_ROOT / "scenarios"


@dataclass(frozen=True)
class Unit:
    id: str
    label: str
    strength: int
    movement: int
    peek: int


@dataclass(frozen=True)
class CardType:
    id: str
    label: str
    influence: int


@dataclass(frozen=True)
class TownDef:
    id: str
    label: str
    x: float
    y: float

    # What this town contributes to the troop ceiling of whatever Empire
    # network it belongs to, and how much it can build there each turn. The two
    # are independent: a poor town can be a garrison depot, a rich one can be
    # unable to build anything.
    supply: int = 1
    production: int = 0


@dataclass(frozen=True)
class GameMap:
    id: str
    label: str
    towns: tuple[TownDef, ...]
    edges: tuple[tuple[str, str], ...]

    @cached_property
    def neighbors(self) -> dict[str, tuple[str, ...]]:
        adjacency: dict[str, list[str]] = {t.id: [] for t in self.towns}
        for a, b in self.edges:
            adjacency[a].append(b)
            adjacency[b].append(a)
        return {tid: tuple(sorted(ns)) for tid, ns in adjacency.items()}

    @cached_property
    def town_ids(self) -> tuple[str, ...]:
        return tuple(t.id for t in self.towns)

    def distances_from(self, town_id: str) -> dict[str, int]:
        """Breadth-first hop counts, for map inspection and bot heuristics."""
        seen = {town_id: 0}
        frontier = [town_id]
        while frontier:
            nxt = []
            for current in frontier:
                for neighbor in self.neighbors[current]:
                    if neighbor not in seen:
                        seen[neighbor] = seen[current] + 1
                        nxt.append(neighbor)
            frontier = nxt
        return seen

    @cached_property
    def diameter(self) -> int:
        return max(
            max(self.distances_from(t).values()) for t in self.town_ids
        )

    @cached_property
    def average_degree(self) -> float:
        return 2 * len(self.edges) / len(self.towns)


@dataclass(frozen=True)
class Scenario:
    id: str
    label: str
    map: GameMap
    unit: Unit
    card_types: dict[str, CardType]
    hand_size: int
    deck: dict[str, int]

    # Supply pays for troops standing; production pays for raising them.
    supply_per_troop: int
    production_cost: int

    empire_start: dict[str, int]
    first_player: "Side"  # noqa: F821 - avoids a circular import at module load
    empire_wins_ties: bool

    # Whether resolution spends the troops committed to it. True is the real
    # rule; False exists so the notebook can reproduce the experiment that
    # established why it has to be True. See Decision 3 in the design doc.

    # -- derived quantities, where the tuning pressure lives ---------------

    @property
    def deck_size(self) -> int:
        return sum(self.deck.values())

    @property
    def total_influence(self) -> int:
        return sum(
            quantity * self.card_types[type_id].influence
            for type_id, quantity in self.deck.items()
        )

    @property
    def turns(self) -> int:
        """Insurgency turns before the deck runs dry."""
        return self.deck_size // self.hand_size

    @property
    def starting_troops(self) -> int:
        return sum(self.empire_start.values())

    @cached_property
    def town_supply(self) -> dict[str, int]:
        return {t.id: t.supply for t in self.map.towns}

    @cached_property
    def town_production(self) -> dict[str, int]:
        return {t.id: t.production for t in self.map.towns}

    @property
    def map_supply(self) -> int:
        """The whole map's supply, i.e. the largest army the board could hold."""
        return sum(t.supply for t in self.map.towns)

    @property
    def total_strength(self) -> int:
        """The most force the Empire could ever have standing at once.

        Not a budget for the whole game the way it used to be: troops are no
        longer spent at resolution, they are limited by supply.
        """
        return (self.map_supply // self.supply_per_troop) * self.unit.strength

    @property
    def empire_premium(self) -> float:
        """Empire's total force as a multiple of the Insurgency's."""
        return self.total_strength / self.total_influence

    def summary(self) -> str:
        return (
            f"{self.label} ({self.id})\n"
            f"  map               {self.map.label} — {len(self.map.towns)} towns, "
            f"avg degree {self.map.average_degree:.2f}, diameter {self.map.diameter}\n"
            f"  game length       {self.turns} Insurgency turns\n"
            f"  total influence   {self.total_influence}\n"
            f"  total strength    {self.total_strength}\n"
            f"  Empire premium    {self.empire_premium:.2f}x"
        )


def _load_json(path: Path) -> dict:
    with open(path) as f:
        return json.load(f)


def load_units() -> dict[str, Unit]:
    raw = _load_json(DATA_DIR / "units.json")
    return {
        uid: Unit(id=uid, label=u["label"], strength=u["strength"],
                  movement=u["movement"], peek=u["peek"])
        for uid, u in raw.items()
    }


def load_card_types() -> dict[str, CardType]:
    raw = _load_json(DATA_DIR / "cards.json")
    return {
        cid: CardType(id=cid, label=c["label"], influence=c["influence"])
        for cid, c in raw.items()
    }


def load_map(map_id: str) -> GameMap:
    raw = _load_json(MAPS_DIR / f"{map_id}.json")
    towns = tuple(
        TownDef(id=t["id"], label=t["label"], x=t["x"], y=t["y"],
                supply=t.get("supply", 1), production=t.get("production", 0))
        for t in raw["towns"]
    )
    known = {t.id for t in towns}
    edges = []
    for a, b in raw["edges"]:
        if a not in known or b not in known:
            raise ValueError(f"map {map_id}: edge references unknown town {a}-{b}")
        edges.append((a, b))
    return GameMap(id=raw["id"], label=raw["label"], towns=towns,
                   edges=tuple(edges))


def load_scenario(scenario_id: str, **overrides) -> Scenario:
    """Load a scenario, optionally overriding fields for a parameter sweep.

    e.g. load_scenario("baseline", generation_rate=2)
    """
    from .engine import Side  # imported here to avoid a circular import

    raw = _load_json(SCENARIOS_DIR / f"{scenario_id}.json")
    units = load_units()
    card_types = load_card_types()
    game_map = load_map(raw["map"])

    fields = dict(
        id=raw["id"],
        label=raw["label"],
        map=game_map,
        unit=units[raw["unit"]],
        card_types=card_types,
        hand_size=raw["hand_size"],
        deck=dict(raw["deck"]),
        supply_per_troop=raw.get("supply_per_troop", 1),
        production_cost=raw.get("production_cost", 1),
        empire_start=dict(raw["empire_start"]),
        first_player=Side(raw["first_player"]),
        empire_wins_ties=raw["empire_wins_ties"],
    )

    for key, value in overrides.items():
        if key not in fields:
            raise ValueError(f"unknown scenario field {key!r}")
        if key == "map" and isinstance(value, str):
            value = load_map(value)
        if key == "unit" and isinstance(value, str):
            value = units[value]
        fields[key] = value

    unknown = set(fields["deck"]) - set(card_types)
    if unknown:
        raise ValueError(f"scenario {scenario_id}: unknown card types {unknown}")

    return Scenario(**fields)


def available_scenarios() -> list[str]:
    return sorted(p.stem for p in SCENARIOS_DIR.glob("*.json"))


def available_maps() -> list[str]:
    return sorted(p.stem for p in MAPS_DIR.glob("*.json"))
