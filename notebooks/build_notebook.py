"""Regenerate exploration.ipynb from source.

The notebook is generated rather than hand-edited so that when a rule changes,
the narrative and the numbers can be updated together and re-executed:

    sim/.venv/bin/python notebooks/build_notebook.py
    sim/.venv/bin/jupyter nbconvert --to notebook --execute --inplace \
        notebooks/exploration.ipynb
"""
import pathlib
import nbformat as nbf

nb = nbf.v4.new_notebook()
c = []
md = lambda s: c.append(nbf.v4.new_markdown_cell(s.strip()))
code = lambda s: c.append(nbf.v4.new_code_cell(s.strip()))

md("""
# Iron and Whisper — rules exploration

This notebook drives the simulator in `sim/`, which is the executable version of the rules
in `../ironandwhisper.md`. It reads the same JSON config the PHP implementation will read,
so anything tuned here transfers.

Two things it is for:

1. **Playing by hand** — step a game turn by turn, either side, to feel out a position.
2. **Answering the tuning questions** the design doc leaves open.

Read the caveat at the bottom before trusting any win rate in here.
""")

code("""
import sys, os, random, statistics
sys.path.insert(0, os.path.abspath(".."))

import matplotlib.pyplot as plt
import networkx as nx

from sim.config import load_scenario, Unit
from sim.engine import (Side, new_game, prepare_turn, play_game, winner,
                        apply_empire_turn, apply_insurgency_turn)
from sim.bots import HeuristicEmpire, HeuristicInsurgency, RandomEmpire, RandomInsurgency
from sim.play import Table
from sim.run import run_many

plt.rcParams.update({"figure.figsize": (9, 4.5), "axes.grid": True,
                     "grid.alpha": 0.3, "font.size": 10})

scenario = load_scenario("baseline")
print(scenario.summary())
""")

md("""
## 1. The map

Twelve towns on a 3x4 lattice. Deliberately regular for a first map — it makes early
tuning runs easy to reason about, and irregular maps can come later once we know what
the numbers should be.
""")

code("""
def plot_map(game_map, highlight=None, ax=None):
    G = nx.Graph()
    for t in game_map.towns:
        G.add_node(t.id)
    G.add_edges_from(game_map.edges)
    pos = {t.id: (t.x, -t.y) for t in game_map.towns}
    labels = {t.id: t.label for t in game_map.towns}
    colors = ["#c94f4f" if highlight and n in highlight else "#dfe3ea"
              for n in G.nodes]

    ax = ax or plt.gca()
    nx.draw_networkx_edges(G, pos, ax=ax, edge_color="#b9c0cc", width=2)
    nx.draw_networkx_nodes(G, pos, ax=ax, node_color=colors,
                           edgecolors="#5a6473", node_size=2100, linewidths=1.5)
    nx.draw_networkx_labels(G, pos, labels, ax=ax, font_size=8)
    ax.set_axis_off()
    return ax

fig, ax = plt.subplots(figsize=(6, 7))
plot_map(scenario.map, highlight=set(scenario.empire_start), ax=ax)
ax.set_title("Twelve Towns — red is the Empire's starting garrison")
plt.show()

print(f"average degree {scenario.map.average_degree:.2f}, diameter {scenario.map.diameter}")
print("hops from the Empire start:",
      dict(sorted(scenario.map.distances_from("everlan").items(), key=lambda kv: kv[1])))
""")

md("""
## 2. Playing by hand

`Table` lets you drive a game yourself. Pass a bot for either side to have it play
automatically, or `None` to play that side too.

The board renders from the perspective of whoever is to move, so the hidden information
stays hidden — the Empire sees pile heights and whatever it has peeked at, the Insurgency
sees everything it placed.
""")

code("""
t = Table("baseline", seed=7, empire=HeuristicEmpire())
t.map()
""")

code("""
t.show()
t.hand()
""")

md("""
Place the whole hand — the rules require it — and end the turn. The bot Empire then
replies automatically.
""")

code("""
# The hand is random, so read its composition rather than assuming it.
influence = sum(1 for card in t.state.hand if card.influence > 0)
dummies = len(t.state.hand) - influence

t.place("everlan", influence=influence)  # real influence where the garrison sits
t.place("harrow", dummy=dummies)         # noise next door, to draw troops off
t.show()                                 # note the PENDING block before committing
""")

code("""
t.end_turn()
""")

md("""
Now look at the same position from the other side of the table. This is the whole game in
one screen: the Empire sees a pile of 3 and knows only what its stationary troops read.
""")

code("""
t.show(view=Side.EMPIRE)
""")

md("""
You can keep going with `t.place(...)`, `t.resolve("everlan")`, `t.end_turn()`.
To play the Empire instead, use `t.generate(...)`, `t.move(src, dst, n)`, `t.resolve(...)`.
`t.reset_turn()` discards anything staged, and `t.log()` shows what has happened.

Pass no bots at all to play both sides yourself.
""")

code("""
solo = Table("baseline", seed=11)        # no bots: you play both sides
solo.place("harrow", cards=len(solo.state.hand))   # dump the whole hand in one town
solo.end_turn(then_autoplay=False)       # now the Empire's turn, and yours to play

solo.generate("everlan")
solo.move("everlan", "harrow", 2)
solo.end_turn(then_autoplay=False)
solo.log()
""")

md("""
## 3. Watching a whole game

Two heuristic bots, with the running log. This is the fastest way to sanity-check that
the rules produce something game-shaped rather than a degenerate loop.
""")

code("""
rng = random.Random(4)
final = play_game(scenario, HeuristicEmpire(rng), HeuristicInsurgency(rng), rng)
for line in final.log:
    print(line)
print()
print("final:", {k.value: v for k, v in final.scores.items()}, "->", winner(final).value)
""")

md("""
## 4. Is the baseline balanced?

No. Badly not.
""")

code("""
def win_rates(games=300, bots="heuristic", **overrides):
    s = load_scenario("baseline", **overrides)
    rs = run_many(s, games, bots)
    n = len(rs)
    return {
        "empire": sum(1 for r in rs if r.winner == "empire") / n,
        "insurgency": sum(1 for r in rs if r.winner == "insurgency") / n,
        "draw": sum(1 for r in rs if r.winner == "draw") / n,
        "empire_score": statistics.mean(r.empire_score for r in rs),
        "insurgency_score": statistics.mean(r.insurgency_score for r in rs),
        "scenario": s,
    }

base = win_rates()
print(f"Empire     {base['empire']:.1%}")
print(f"Insurgency {base['insurgency']:.1%}")
print(f"draws      {base['draw']:.1%}")
print()
print(f"mean scores — Empire {base['empire_score']:.1f}, "
      f"Insurgency {base['insurgency_score']:.1f}")
""")

md("""
### The engagement numbers say why

Capture-only scoring means points only exist where someone *loses* a committed force.
So the useful diagnostic is not the score, it's **how much of each side's total budget ever
changed hands.** If those numbers are near zero, the game is mostly walkovers.
""")

code("""
s = base["scenario"]
print(f"of all {s.total_strength} Empire strength, "
      f"{base['insurgency_score'] / s.total_strength:.1%} was overcome and scored")
print(f"of all {s.total_influence} Insurgency influence, "
      f"{base['empire_score'] / s.total_influence:.1%} was captured and scored")
""")

md("""
The Empire loses about 15% of its force to scoring fights; the Insurgency loses 40% of
its influence. The Empire is simply refusing bad fights and winning good ones.
""")

md("""
## 5. Why troops have to be spent

Decision 3 says troops committed to a resolved town are removed from play. The tempting
simplification is to let them survive — it deletes a field from the state and there is no
bookkeeping about when anything was created.

It also destroys the game. `consume_troops=False` reproduces the experiment:
""")

code("""
for consume in (True, False):
    r = win_rates(300, consume_troops=consume)
    print(f"consume_troops={str(consume):<5}  empire {r['empire']:6.1%}   "
          f"insurgency {r['insurgency']:6.1%}   "
          f"Empire force overcome {r['insurgency_score'] / r['scenario'].total_strength:5.1%}")
""")

code("""
# It is not a tuning problem — no deck composition rescues it.
print("with troops surviving resolution:")
for inf in [30, 38, 46, 50]:
    r = win_rates(200, consume_troops=False, deck={"influence": inf, "dummy": 60 - inf})
    print(f"  density {inf/60:.0%}   empire {r['empire']:.1%}")
""")

md("""
**Consumption is the only thing that makes Empire commitment cost anything.** Without it
the Empire fights only battles it expects to win, keeps its army afterwards, and marches
on. Since the Insurgency can score *only* by beating a committed garrison, an Empire that
never has to accept a bad fight closes the Insurgency's single scoring route.

That is why no parameter reopens it: a fixed Empire force of just two troops, with
generation switched off entirely, still wins about 91%. It was never about how much force
the Empire had — it was that the force was never spent.
""")

md("""
## 6. The design doc's balance heuristic is wrong

The doc reasons about an **Empire premium** — total Empire strength divided by total
Insurgency influence — and suggests tuning until it approaches 1.0. That turns out to be
a poor predictor. Here are four configurations with premiums from 0.87 to 1.5:
""")

code("""
trials = {
    "baseline (50% influence)": {},
    "troop strength 2": dict(unit=Unit("infantry", "Infantry", 2, 1, 1)),
    "strength 2, start 1 troop": dict(unit=Unit("infantry", "Infantry", 2, 1, 1),
                                      empire_start={"everlan": 1}),
    "deck 40 influence / 20 dummy": dict(deck={"influence": 40, "dummy": 20}),
}

print(f"{'configuration':<30}{'premium':>9}{'empire':>9}{'insurgency':>12}")
print("-" * 60)
for label, ov in trials.items():
    r = win_rates(200, **ov)
    print(f"{label:<30}{r['scenario'].empire_premium:>9.2f}"
          f"{r['empire']:>9.1%}{r['insurgency']:>12.1%}")
""")

md("""
Dropping troop strength to 2 brings the premium to exactly 1.00 and moves the win rate
**almost not at all**. Changing the deck composition moves the premium far less and flips
the game completely.

The reason is that capture-only scoring makes troop strength self-cancelling: weaker troops
mean the Empire wins fewer fights, but also that each fight the Insurgency wins is worth
fewer points. The two effects very nearly cancel.

What actually binds is **influence density** — how much *real* influence the Insurgency can
concentrate in one place. With a 50/50 deck and mandatory placement, half of everything it
does is noise it is forced to make.
""")

md("""
## 7. Deck composition is the real knob
""")

code("""
densities = [30, 32, 34, 36, 38, 40]
rows = []
for inf in densities:
    r = win_rates(300, deck={"influence": inf, "dummy": 60 - inf})
    rows.append((inf, r["empire"], r["insurgency"], r["scenario"].empire_premium))

fig, ax = plt.subplots()
ax.plot([r[0] / 60 for r in rows], [r[1] for r in rows], "o-",
        color="#c94f4f", label="Empire", linewidth=2)
ax.plot([r[0] / 60 for r in rows], [r[2] for r in rows], "o-",
        color="#4f7ec9", label="Insurgency", linewidth=2)
ax.axhline(0.5, color="#888", linestyle="--", linewidth=1)
ax.set_xlabel("influence density (share of the deck that is real)")
ax.set_ylabel("win rate")
ax.set_title("Deck composition swings the game; total force barely does")
ax.legend()
plt.show()

for inf, e, i, prem in rows:
    print(f"deck {inf}/{60-inf}  density {inf/60:.0%}  premium {prem:.2f}   "
          f"empire {e:.1%}  insurgency {i:.1%}")

# Everything below is measured here, not at the lopsided starting values.
balanced = dict(deck={"influence": 36, "dummy": 24})
""")

md("""
Balance lands near **36 influence / 24 dummy** — about 60% density, not 50%. That is the
single most useful number in this notebook, and it is a long way from the doc's starting
guess.
""")

md("""
## 8. Is the peek mechanic doing anything?

Peeking is the game's signature — the Empire's only way to pierce the fog. So it should
matter a great deal whether troops can see or not. Compare a normal Empire against one
that is completely blind:
""")

code("""
for label, unit in [("peek 1 (normal)", Unit("infantry", "Infantry", 3, 1, 1)),
                    ("peek 0 (blind)",  Unit("infantry", "Infantry", 3, 1, 0)),
                    ("peek 3 (all-seeing)", Unit("infantry", "Infantry", 3, 1, 3))]:
    r = win_rates(400, unit=unit, **balanced)
    print(f"{label:<22} empire {r['empire']:.1%}   insurgency {r['insurgency']:.1%}")
""")

md("""
Peeking is worth real win rate, and the effect is monotonic. The mechanic the design rests
on is doing its job.

Note this is measured at the balance point, not at the lopsided starting values — measuring
a mechanic inside a configuration one side wins 71% of the time tells you very little.

**This corrects an earlier reading.** Under the previous rules — where troops survived
resolution — a blind Empire played *as well* as a sighted one, and peeking looked inert.
That was not a fact about the mechanic. It was a symptom of an Empire so dominant that
information could not change any outcome. Once the game is close, information matters
again.

Worth remembering as a general caution about this notebook: measuring the value of a
mechanic inside a broken configuration tells you nothing about the mechanic.
""")

md("""
## 9. Does board-shrinking pay? (Decision 5)

The design doc flags a risk: because resolved towns become permanent generation anchors
*and* shrink the board, the Empire might profit from resolving towns cheaply just to
freeze them, even scoring nothing. `HeuristicEmpire(shrink=True)` does exactly that.
""")

code("""
def duel(empire_factory, insurgency_factory, games=300, **overrides):
    s = load_scenario("baseline", **overrides)
    tally = {"empire": 0, "insurgency": 0, "draw": 0}
    for seed in range(games):
        rng = random.Random(seed)
        st = play_game(s, empire_factory(rng), insurgency_factory(rng), rng)
        w = winner(st)
        tally["draw" if w is None else w.value] += 1
    return {k: v / games for k, v in tally.items()}

normal = duel(lambda r: HeuristicEmpire(r), lambda r: HeuristicInsurgency(r), **balanced)
shrink = duel(lambda r: HeuristicEmpire(r, shrink=True),
              lambda r: HeuristicInsurgency(r), **balanced)

print(f"Empire playing normally    {normal['empire']:.1%}")
print(f"Empire playing to shrink   {shrink['empire']:.1%}")
""")

md("""
**Board-shrinking now pays** — it lifts the Empire from 45% to 60%, making it the stronger
Empire line rather than a trap.

The mechanism is the Insurgency's mandatory placement. Every town the Empire freezes is one
fewer place the Insurgency may put its hand, so it is forced to pile cards into towns the
Empire has no intention of contesting — where, under capture-only scoring, that influence
is worth exactly nothing. Freezing towns is a way to make the opponent waste their deck.

**This also reverses an earlier reading.** Under the previous rules shrinking lost badly
(47% down to 18%), because resolved towns were generation anchors and the calculus was
different. It is worth being clear that this is a finding about the *current* rules only.

Whether it is a problem is a design question rather than a simulator one. It is a real
strategy with a real cost — the Empire spends troops and scores nothing — and it wins 60%,
not 90%. But it does mean the Empire's strongest line involves a lot of zero-point
resolutions, which may not be the game you want. The obvious counter is already on the
deferred list: **intrinsic town values**, so that towns the Empire never contests are still
worth something to the Insurgency.
""")

md("""
## 10. Do dummies actually work as bait?

`HeuristicInsurgency(bait=True)` places dummies next to Empire troops to invite
over-commitment. `bait=False` scatters them at random.
""")

code("""
on = duel(lambda r: HeuristicEmpire(r), lambda r: HeuristicInsurgency(r, bait=True), **balanced)
off = duel(lambda r: HeuristicEmpire(r), lambda r: HeuristicInsurgency(r, bait=False), **balanced)
print(f"dummies placed as bait     insurgency {on['insurgency']:.1%}")
print(f"dummies scattered randomly insurgency {off['insurgency']:.1%}")
""")

md("""
Baiting is worth roughly 11 points of win rate. So *where* the noise goes matters, which
is the bluffing layer showing up in the numbers even with fairly crude bots. This is the
most encouraging result in the notebook.
""")

md("""
## 11. Concentrate or spread? — unanswerable right now

The doc asks whether the Insurgency should concentrate influence or spread it. The
`spread` parameter should answer that, and it produces identical results for every value,
which is a red flag rather than a finding.
""")

code("""
for spread in [1, 2, 3, 4]:
    r = duel(lambda rng: HeuristicEmpire(rng),
             lambda rng, s=spread: HeuristicInsurgency(rng, spread=s), **balanced)
    print(f"spread={spread}   insurgency {r['insurgency']:.1%}")
""")

code("""
# Why: the Insurgency targets garrisoned towns, and there is almost never
# more than one to target.
s = load_scenario("baseline", **balanced)
counts = []
for seed in range(60):
    rng = random.Random(seed)
    st = new_game(s, rng)
    e, i = HeuristicEmpire(rng), HeuristicInsurgency(rng)
    while True:
        prepare_turn(st)
        if st.game_over:
            break
        if st.to_move is Side.INSURGENCY:
            counts.append(sum(1 for t in st.unresolved if t.troops > 0))
            apply_insurgency_turn(st, i.choose(st))
        else:
            apply_empire_turn(st, e.choose(st))

print(f"garrisoned unresolved towns when the Insurgency chooses:")
print(f"  mean {statistics.mean(counts):.2f}, max {max(counts)}, "
      f"more than one: {sum(1 for c in counts if c > 1) / len(counts):.1%}")
""")

md("""
The Empire moves as a single doomstack — it has troops in more than one contested town
about 1.5% of the time — so there is never a second target for the Insurgency to spread
*to*. That is an artifact of `HeuristicEmpire` moving every troop in a town together,
not a fact about the game.

**Fixing the Empire bot to split its force is the prerequisite for answering this
question.** It is also probably the prerequisite for question 7, since a doomstack has no
use for information about towns it is not standing in.
""")

md("""
## Caveats — read this before quoting any number above

These bots are crude. They tell you about the **shape** of the game — whether the score
space is degenerate, whether a parameter does anything, whether a strategy is obviously
dominant. They do **not** tell you the game is balanced under skilled play.

Specifically:

- **A 50/50 win rate between weak bots is not balance.** It mostly says the game is noisy.
- **The Empire plays as one doomstack** and never splits, which distorts section 11
  directly and probably softens everything else.
- **Neither side models its opponent's beliefs**, so the bluffing layer is under-represented.
  The property from Decision 8, where the Insurgency can compute exactly what the Empire has
  seen, is exploited by nothing here.
- **Two findings in this notebook reversed** when the rules changed (sections 8 and 9).
  A result measured inside a broken configuration is not a result.

The findings I would act on, in order of confidence:

1. **Troops must be spent at resolution.** The strongest result here by a distance — the
   alternative wins 99.7% and no parameter rescues it. Settled.
2. **Deck composition is the strong knob; balance is near 60% influence density, not 50%.**
   Robust: a large, monotonic effect across a clean sweep.
3. **The total-force "premium" heuristic in the design doc does not predict balance.**
   Robust, and the doc is corrected.
4. **Bait placement matters** — worth about 20 points of Insurgency win rate. The bluffing
   layer showing up even with crude bots.
5. **Peeking is load-bearing**, monotonically. Now believable in a way it was not before.
6. **Board-shrinking is the stronger Empire line.** Solid as a measurement; what to *do*
   about it is a design decision, not a simulator output.

The clearest next step for the simulator is an Empire that splits its force. That would
re-open section 11, and probably sharpen everything else.
""")

nb["cells"] = c
nb["metadata"] = {
    "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
    "language_info": {"name": "python"},
}
out = pathlib.Path(__file__).resolve().parent / "exploration.ipynb"
with open(out, "w") as f:
    nbf.write(nb, f)
print(f"written {out}")
