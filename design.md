# Iron and Whispers — Design Notes

**The rules live in [`rules.html`](rules.html), not here.** That is the manual a player
reads: what the pieces are, what the symbols mean, and how a turn goes. This file is the
other half — *why* each rule is what it is, what was tried instead, and what the
measurements said. Split out 2026-09-20, because one file was doing both jobs and the
player-facing half could not carry sentences like "an earlier version measured 99.7%
Empire wins".

Read this before changing a rule. Every decision below was paid for, several of them
twice, and the reasoning is the only thing that stops a fixed problem being reintroduced
as a good idea.

The design goal, for orientation: a *minimal* rule set — a small number of mechanics that
interact to produce tension and bluffing, rather than realism. The Empire acts entirely in
the open, moving troops; the Insurgency acts almost entirely in secret, seeding hidden
cards. Every rule is settled. The numbers in [Parameters](#parameters) are expected to
move; the rules are not.

---

## Vocabulary

Settled 2026-09-13, after a table found the old words confusing.

**Presence** is the one quantity the game compares. Both sides accumulate it in a town,
and at a resolution the higher total wins. It used to be called *influence* on the rebel
side and *strength* on the Empire's, which was two names for one thing in the exact place
where a player has to compare them. Where the source matters, say **card presence** and
**troop presence**; where it does not, it is just presence.

**Agents** are the Insurgency's cards, named to parallel *troops* — a vehicle that carries
presence onto the board. An `Agent +0` is a decoy: still an agent, worth nothing at a
resolution, and indistinguishable from any other face-down card.

The word *presence* previously meant something else — the Decision 5 gate on whether you
may resolve a town at all. That use is gone, because it was ambiguous anyway: it was never
clear whether it meant holding troops, holding cards, or having won the place. The three
now have their own words:

| word | means |
|---|---|
| **occupied** | the Empire has troops standing there |
| **seeded** | the Insurgency has at least one card in the pile |
| **controlled** | resolved, and won by that side |

Decision 5 therefore reads "you may only resolve a town you are in" rather than naming a
requirement after a noun.

Card *type ids* are still `influence0`…`influence3` in `data/cards.json` and the scenario
deck blocks. They are arbitrary identifiers that no player sees, and renaming them would
churn every scenario file for nothing.

---

## Parameters

The tunable knobs, and where the tuning pressure lives.

> **This table is the graded configuration the measurements below were taken on, not what
> is currently played.** The live numbers are in `scenarios/baseline.json`, and `rules.html`
> quotes those: cards worth 0 or 1, a troop worth 1 presence, every town supplying 2, hand
> of 3. The simplification was deliberate and is recorded in `CLAUDE.md`. Both are kept
> because a measurement is only meaningful alongside the parameters it was taken on.

| Parameter | Starting value | Notes |
|---|---|---|
| Number of towns | 12 | Average degree ~3, diameter 4–5. |
| Empire starting position | 3 troops across two adjacent towns | Board starts with no cards on it. |
| Town supply | 5 to 7, no two towns alike | What a town adds to its network's troop ceiling. |
| Town production | 1, at Everlan and Kirn only | Troops the town can build per turn. |
| Supply per troop | 2 | Divides network supply into a troop ceiling. |
| Infantry — Presence | 3 | Deliberately higher than one card so troops are "heavy." |
| Infantry — Movement | 1 | Edges per turn. |
| Infantry — Peek | 1 | Cards a stationary troop turns face up each turn. |
| Rebel hand size | 5 | Drawn and fully placed each Insurgency turn. |
| Rebel deck size | 60 | Finite, no reshuffle. Sets game length. |
| Deck composition | 24×0, 24×1, 9×2, 3×3 | Graded presence. There is no separate "dummy": a bluff is a card worth 0. |
| Town point values | none (MVP) | Capture-only scoring; intrinsic values are deferred. |

**Derived quantities**, which is where the tuning pressure actually lives:

- **Game length** = `deck_size / hand_size` = **12 Insurgency turns**. Deterministic, because the whole hand must be placed every turn.
- **Total Insurgency presence** = the deck's values summed = **51**.
- **Total Empire presence** = the whole map's supply, if the Empire ever held all of it = `(72 ÷ 2) × 3` = **108**. Unlike the Insurgency's presence this is a ceiling, not a budget: troops are no longer spent, they are limited by supply.

The Empire commands about **0.88×** the Insurgency's total force. That is deliberate but temporary: it is headroom for the network-presence change under design, which will raise the Empire's effective presence considerably. As the rules stand today it is badly Insurgency-favoured — see the measurements below.

**Card values decouple the clock from the economy.** Deck size sets game length, because the whole hand is placed every turn: 60 cards ÷ 5 = 12 turns, exactly. Card *values* set the Insurgency's economy. Grading the cards is therefore the only way to change what the Insurgency can buy without changing how long the game lasts.

> **Simulation says this premium is the wrong thing to tune.** See `notebooks/exploration.ipynb`. Bringing the premium to exactly 1.00 by lowering troop presence moves the win rate almost not at all, because capture-only scoring makes troop presence self-cancelling: weaker troops win fewer fights, but each fight the Insurgency wins is also worth fewer points, and the two effects nearly cancel.
>
> The knob that actually moves the game is **presence density** — the share of the deck that is real. At the original 50:50 the Empire won about 73% of games; balance against the current bots lands near **36 presence : 24 dummy**, roughly 60% density, and the table above now carries that.
>
> **Nobody has played any of it.** These are simulator outputs adopted as starting points, measured against bots that are not good players.
>
> **And "not good players" turned out to be load-bearing.** Every Empire figure recorded here was measured against the heuristic Insurgency, which piles presence onto the richest garrison it can see and scatters the rest at random. `MistBot`, a rebel bot that plays the geography instead — cash every town already won, take a lead only where the Empire has no troops in reach of answering it, spend the bluffs on empty ground beside a garrison — beats `GlobEmpire` in **100% of 300 games** at baseline, mean score 5.8 to 0.2, where the heuristic rebels lose 63% of them. The Empire's balance problem is therefore worse than any number above says, and a win rate is only ever a statement about the opponent it was measured against.

> **Graded cards, measured.** 1000 games per configuration, heuristic bots, current rules. `scenarios/` holds each of these so they can be re-run.
>
> | deck | total presence | Empire wins | with no peeking | peeking is worth |
> |---|---|---|---|---|
> | `flat` — 36×1, 24×0 | 36 | 44.4% | 34.4% | **10.0 points** |
> | `graded36` — 15×1, 6×2, 3×3, 36×0 | 36 | 57.0% | 55.2% | **1.8 points** |
> | `baseline` — 24×1, 9×2, 3×3, 24×0 | 51 | 12.2% | 10.9% | 1.3 points |
>
> Two things fall out, and one of them is uncomfortable.
>
> **Grading helps the Empire**, holding the economy fixed: 44.4% → 57.0%. Concentrating the same presence into fewer, bigger cards means more towns hold nothing but noise, and the Insurgency has fewer real cards to spread across twelve towns. So grading is not in itself a way to strengthen the Insurgency — the economy increase to 51 is what does that, and it does it hard.
>
> **Peeking measures as worth much less with graded cards** — 10.0 points down to 1.8 — which is the opposite of the design intent. Do not take that at face value. Each look is genuinely more informative: per-card presence variance triples, from 0.24 to 0.74. What the number really says is that *these bots* cannot cash the extra information, because the Empire bot reduces every pile to one expected-value estimate and marches at the biggest number. "There is a 3 in that town" and "that town estimates at 1.8" are the same thing to it. A human who turns over a 3 knows something categorical. The simulator can measure balance; it is a poor instrument for whether a decision is interesting, and this is exactly where it is weakest.

---

## Decisions & Constraints

Settled rules and the reasoning behind them. Recorded so they aren't silently re-litigated.

### 1. A finite deck is the clock, and exhaustion resolves everything at once

**Why:** resolution is optional and there is no pass rule, so two cautious players could otherwise stall forever. Mass resolution at exhaustion fixes this by making refusal-to-resolve useless — the pot gets cashed regardless, so declining only surrenders the timing. It also reframes resolution from "how I score" into "how I lock in a win before it can be reversed," which is a better decision to put in front of a player.

### 2. Supply is a ceiling on troops; production builds them

Two numbers per town, independent of each other:

- **Supply** — what the town contributes to a troop ceiling.
- **Production** — how many troops it can build per turn, if the Empire stands there.

A poor town can be a depot; a rich one can be unable to build anything.

**Networks.** A town is in an Empire network if the Empire stands in it, and two occupied towns are linked if the map links them. Each network's supply is summed and divided by `supply_per_troop`: that is the most troops it can keep standing. Networks pool separately, so cutting one in half gives two smaller ceilings.

**Building.** A production town raises troops up to its own production, provided the Empire **holds** it — standing in it counts, and so does having won it. Requiring troops *on the spot* was a chicken-and-egg: an Empire that lost the last troop in a factory it had already taken could never raise another there, and the rest of the game played itself out for nothing. Requiring nothing at all was worse in the other direction: the Empire drew troops out of a production town it had never been near, a free second factory it never had to take. The rebels winning the town stops it for good, which makes a production town the sharpest target on the board for a side whose scoring is otherwise capture-only. Supply is **not** a limit on building: the ceiling caps what a network can *keep*, not what it can raise, and building past it is legal — attrition settles it a turn later, with a mark on the board in between. So you may raise troops and march them out to the supply that will feed them in the same motion, and you may deliberately overshoot in the knowledge that you have a turn to find them ground. Troops appear where they were built and march from there — there is no teleporting to the front, so distance is real.

**Denial.** A town the Insurgency wins supplies nothing and builds nothing, ever again. The Empire may march back into it — the town is resolved, so it can never be contested a second time — and it will hold a line through it, but it will never feed one. This is what makes taking a town worth something lasting to a side that cannot build a network of its own: it does not capture supply, it destroys it.

**Attrition.** A network that cannot supply its troops does not starve them at once. At the end of the Empire's turn every town in a short network is **marked**, showing how many of its troops are forecast to go. If the network is still short at the end of the Empire's *next* turn, those troops starve and score for the Insurgency. Repair the line in between — take ground, or spread back out onto supply you abandoned — and the mark clears with nobody lost.

The mark is a forecast, not a reservation: what actually falls is recomputed when it falls, so moving troops moves where it lands. Within a town there is no choice to make, because a town holds a count of troops rather than troops; across a network, losses come off the largest garrisons first unless the Empire names somewhere.

**Why:** this is the Empire's whole character in one subsystem. It does not out-fight the Insurgency, it out-organises it — and an organisation can be cut. It also produces the tension the game needs from the Empire's side: **spread for economy, concentrate for battle.** Supply is per town, so thinning out raises your ceiling; attack presence is local, so thin garrisons lose fights and are removed. The two pull against each other every turn.

> **An older version of this rule read "one troop per turn, anywhere the Empire already stands", with a warning that per-town generation was degenerate** — the Empire splits up to occupy more towns, occupies more towns to generate more, and out-produces the Insurgency deck. Per-town production is exactly what that warned against, and it is safe now for a reason that did not hold then: dilution is no longer free. A thin garrison loses its local fight, and the troops in it are removed and scored. Spreading buys economy and sells safety, which is a trade rather than a strictly correct move.

> **Balance is very sensitive to ordinary towns, and not to capitals.** Sweeping the values found a cliff: at 2.5 supply-troops per ordinary town the Empire wins about 38%, at 3.0 about 65%, with nothing in between reachable on a uniform map. Raising the *capitals* instead moved it the wrong way — concentrating supply makes the Empire fragile, because losing one node collapses a ceiling and starves an army into the Insurgency's score. The map is therefore deliberately heterogeneous, which straddles the cliff and lands near even. Treat the cliff as a property of the current bots as much as of the game: it is where their strategy flips, and a human plays the margin differently.

> **A rejected version had the network contribute *attack presence* rather than supply**, so every fight was backed by the whole army. It fails: a troop contributes the same presence wherever it stands, so there is never a reason to expose one. The Empire wins a town, parks its army there permanently out of reach, and pushes forward with a single token troop. That is the failure in Decision 3 wearing a different hat — the Empire never accepts a bad fight, and the Insurgency's only scoring route closes. Network-as-production has no such incentive, because collecting a town's supply costs a garrison that counts against the very ceiling it raises.

### 3. The loser's commitment is taken off the board; the winner's stays

When a town resolves, whoever lost has their commitment removed from the board and scored by the winner. The Empire loses a town: its troops there are taken and the Insurgency scores their presence. The Empire wins: the cards are taken and it scores their presence, and **its garrison stays**, so the town goes on carrying supply and, if it can, building.

**Why:** it reads correctly — you take the enemy's stuff — and it is one rule where there used to be two. It also means winning a town is worth something lasting rather than converting your army into points, which is what makes the Empire's game about holding a map rather than trading pieces for score.

Resolved towns are never contested again, so a town the Empire won and garrisons is permanently safe. That is deliberate: **an Empire that locks down a network of supply lines has won, and that is the Empire's thesis.** The Insurgency is not building a rival network; it is denying that any network can exist. Its counterplay is to take the junctions before they lock.

> **An earlier version spent the winner's troops too**, on the grounds that commitment should cost something. The simulator showed the opposite arrangement — troops always surviving — was catastrophic at **99.7%** Empire wins, because an Empire that keeps its army never has to accept a bad fight and the Insurgency's only scoring route closes. What reopens it here is that the Empire's troops *are* removed when it loses, and that supply gives the Insurgency a second way to take them off the board without winning a fight at all.

> **Simulation result, and a corrected earlier decision.** We first tried the opposite — troops survive resolution — on the grounds that it removes a field from the state. It makes the game degenerate. The Empire wins **99.7%** at the starting parameters, **96.7%** even at 83% presence density, and **90.8%** with a fixed force of only two troops and no generation at all. Across every configuration, under 4% of Empire presence was ever overcome.
>
> The mechanism: consumption is the only thing that makes Empire commitment cost anything. Without it the Empire fights only battles it expects to win, keeps its army afterwards, and marches on. Since the Insurgency can score *only* by beating a committed garrison, an Empire that never has to accept a bad fight closes the Insurgency's only scoring route entirely. No parameter reopens it — which is why a two-troop Empire still wins 91%.
>
> The complexity that motivated the experiment came from an earlier version of this decision in which frozen troops still anchored generation, forcing two categories of troop. Dropping *that* gives the same simplicity — one integer per town, set to zero on resolution — while keeping the budget game.

### 4. Resolution is free, once per turn, and happens *first*

Declare before you do anything else. A resolution is judged on the board as your opponent left it: the Empire cannot march in and cash out on arrival, and the Insurgency cannot place exactly enough and then collect.

**Why free and once per turn:** making it cost a whole turn is too expensive for the Empire, and impossible to price for the Insurgency, which is compelled to place its whole hand every turn (Decision 6) and so has nothing to trade away. One per turn caps the rate at two towns per round, ample for a twelve-town map.

**Why first:** because it was the last thing, and that made every resolution risk-free. Whoever declared did so with complete knowledge and no reply — the Empire marched a single troop into a lightly-held town and took it on arrival; the Insurgency dropped exactly enough presence on a garrison and cashed it in the same breath. Moving resolution to the start of the turn fixes both with an ordering rather than a restriction: what you commit has to survive your opponent's turn before you can collect on it.

The consequence worth knowing is that your opponent gets the first shot at anything you just committed. March into a seeded town and the rebels may resolve it before you can. That is the cost of advancing, and the game had no such cost before.

**Why a phase of its own.** First in the rules is not the same as first on the screen. While the resolution was staged alongside the rest of the turn and sent with it, nobody could know how it would come out while they were planning around it — so the town being resolved had to be closed to placement and closed as a march origin, and neither restriction could be explained. Taking the resolution as a separate action, applied immediately, removes both: the cards turn over, the score moves, and the board everyone then plans against is the real one. It costs the ability to change your mind, which is why it is asked for on its own.

### 5. You may only resolve a town where you have presence

**Why:** without it there is a degenerate line where the Empire resolves empty towns from anywhere, freezing the map for free, shrinking the board and forcing the Insurgency to overstack. The requirement closes it and reads correctly — you cannot force a confrontation somewhere you do not exist.

### 6. The Insurgency must place its entire hand every turn

**Why:** forced placement is what makes pile height uninformative. If cards could be held, you would place only when it helped, and pile growth would start to correlate with real presence — the Empire could read the board directly. Being forced to dump an all-dummy hand somewhere generates the noise the entire bluffing layer depends on.

It also makes the deck an exact clock, which is lost if the placement rate can vary.

Under Decision 9, this is less punishing than it sounds: **dummies are free to lose**, so forced placement is the Insurgency's cheap noise generator while it rations real presence.

### 6a. The Insurgency scores every Empire troop that leaves the board

**Why:** it is the same rule it always had — score the presence you take off the enemy — but it now covers two ways of taking it. Beat a garrison at a resolution and you score it. Cut the supply line that fed it and it starves, and you score that too.

**Why supply does not cap building.** It caps how many troops a network can *keep*. Making it cap production too was one word doing two jobs, and it cost the Empire a natural move: raise troops and march them out to the ground that will feed them, in one turn. It also meant a network at its ceiling had an idle factory, which reads as a bug at the table rather than as a rule. Building past it is now legal, and the overshoot is charged to attrition — which, with a turn of grace, makes it a stated risk instead of an instant loss.

**Why attrition waits a turn.** Because massing was self-defeating in a way nobody could see coming. Supply comes only from towns the Empire *occupies*, so concentrating an army destroys the supply that would have fed it: march eight troops into one town out of three and the ceiling collapses at the moment they arrive. Under immediate attrition six of them died inside the commit, between the player's turn and the opponent's, at the one point in the game where nobody is looking at the board — and the rebels then resolved the town against the two survivors.

A turn of grace makes the same move a decision instead of an ambush. Mass this turn; resolve at full presence next turn, since resolution comes first (Decision 4); then either spread back out onto the supply or accept the loss and consolidate. It also subsumes the older reason for ending attrition at the end of the turn rather than the start — a line the Insurgency cut can still be answered, with a full turn to do it in rather than the remainder of one.

The mark is public, because the networks are computed from the board and anyone can see them. An overextended Empire is visible, and the Insurgency's counter is to decline the fight and wait: resolving a town whose garrison is about to starve pays for troops that were leaving anyway.

This is what keeps the Insurgency's strategy and its scoring pointed the same way. Severing a line is the most narratively rebel thing in the game, and it would be odd if it paid nothing. It also gives the Empire a real decision with no rule attached: **how close to your ceiling dare you run?** An army at maximum loses troops the moment anything is cut; slack costs tempo and buys resilience.

### 7. The Empire wins ties

**Why:** thematic (the entrenched defender holds), trivial to implement, and easy to reason about at the table — with troops at presence 3, the Insurgency always knows it must *beat* a multiple of 3 rather than match it.

Alternative considered: ties go to whoever did *not* declare, which makes speculative resolution risky. Better in isolation, but one more thing to hold in your head, and the MVP does not need it.

### 8. Looking turns the top card face up, and it stays that way

**Why:** this self-bookkeeps, and it is what you would do with real cards. Each town has a face-down pile and a face-up area beside it. A look takes the top card of the pile and lays it face up in that area, where it stays for the rest of the game. New cards go on top of the face-down pile.

**Face-up cards still count in full at resolution.** Turning a card over tells you what it is; it does not take it out of the fight.

There is therefore no rotation to track, nothing to cap, and no such thing as a wasted look: the face-down pile holds only cards nobody has seen, so a look always buys information, and when the pile is empty the garrison has read the town and waits for the Insurgency to add more.

> **This was originally a rotating pile** — draw from the top, return to the bottom, cycle forever. It behaved almost identically, because a garrison that has cycled a pile already knows everything in it, and the simulator measured the difference at well under a percentage point of win rate. The face-up version was adopted because it is simpler to state, simpler to implement, and matches what the table looks like.

Fine print:
- Several cards placed into the same town on the same turn go on **one at a time, in the order the Insurgency chooses**, so the last one placed is the first one read. This is a real lever, not bookkeeping: it decides which of this turn's cards a garrison sees first, and it is the Insurgency's to set.
- Multiple stationary troops in one town **stack their Peek**.
- A troop must start *and* end the turn in the town without moving. Troops arriving this turn cannot look until next turn.
- A look is **public**. The card is face up on the table, so both players see it. This gives the Insurgency nothing it did not have: it knows every card it placed and troop positions are visible, so it could always compute exactly what the Empire had seen. Putting the cards face up only spares both players the arithmetic.

Two consequences worth knowing:

- **The Empire always sees the newest card first**, since new cards land on top of the face-down pile. A garrison therefore gives excellent *recent* intelligence and poor *historical* coverage. This self-balances on throughput: a stationary troop reads one card per turn, so if the Insurgency dumps three cards a turn into that town the Empire falls behind 3:1 and needs several stationary troops to keep pace — and stationary troops are not advancing anywhere.
- **Both players know exactly what the Empire has seen**, because it is lying face up in front of them. You can therefore build a bluff on a *known* false belief, which is a far better foundation than a random-sample peek that only ever yields a probability distribution over what your opponent thinks.
- **A read town is not a safe town.** Once the Empire has turned a pile face up it knows precisely what that town is worth, but the cards still count, and the Insurgency can keep adding to the face-down pile on top.

### 9. Everything in a resolved town stays, face up, out of play

**Why:** simpler than removing pieces to a discard pile, and the board becomes a record of the game. Resolution turns anything still face down face up, so the whole town is public afterwards, and the cards do nothing further. Troops do **not** stay: they are spent (Decision 3), so a resolved town ends up holding a face-up pile and no garrison, and stops anchoring generation.

The significant side effect: **face-up resolved piles make the finite deck countable.** By mid-game both players can count revealed presence and infer how much real presence remains in the deck and in hand. The fog thins on its own as the game progresses, so early play is pure guessing and the endgame is sharp and calculable — and dummies get weaker precisely when the stakes are highest. Card counting becomes a genuine skill without a single extra rule.

### 10. The Insurgency moves first

**Why:** with an empty board, an Empire first turn is nearly a null turn — nothing to look at, nowhere meaningful to move. Giving the Insurgency the opening seed also matches the theme: the insurgency has the initiative, the empire reacts.

---

## Implementation Notes

Rules content is **data, not code**, so the numbers above can be tuned without touching game logic. The Python simulator and the PHP game both read these same files, which is what makes tuning transfer:

- `data/units.json` — unit types: presence, movement, peek.
- `data/cards.json` — card types: presence value, share of deck.
- `maps/*.json` — pure geography: towns (id, label, x/y for rendering), edges.
- `scenarios/*.json` — references a map and sets the knobs: hand size, deck size and composition, generation rate, starting Empire placement.

Map and scenario are split because every parameter is still a guess. The separation allows the same board to be run at many parameter settings without duplicating the graph, which is exactly the sweep needed to settle the tuning questions.

---

## Next Steps — Deferred Richness

Explicitly **out of the MVP**. These are the directions worth growing into once the core loop is proven fun. Each should be a modification of the simple rules above, not a replacement.

**Generation:** the most promising direction is *earned capitals* — make generation per-anchor rather than one-per-turn, so the Empire's recruitment network grows out of where it actually fought. Note this requires revisiting Decision 3 at the same time (see the coupling warning).

**Richer cards** (revealed at resolution alongside plain presence):
- A card worth *extra* presence.
- A card that *doubles* the town's stakes — a way to gamble on a contested town.
- A card that *resets* a town (clears the pile) without resolving it.
- A card that *delays* resolution — the town doesn't lock even though someone called it.

**Richer Empire units** (the Empire trades card-play flexibility for unit variety):
- **Scouts** — fast movement, low presence.
- **Political agents** — troops that grant extra looks per action.
- Generally, new units defined by trading among Presence / Movement / Peek.

**Information mechanics** (letting the Empire partially pierce the fog):
- The Empire occasionally gets to see the Insurgency's hand before placement, or learn *how many* real cards are in it, or the current real/dummy ratio.

**Scoring / map variants:**
- Intrinsic town values (small towns vs. cities) so *where* you fight matters, not just how hard.

**Flow variants:**
- ~~A Go-style **mutual pass**~~ — built, 2026-09-11. See Decision 1.
