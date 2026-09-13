# Iron and Whisper — Core Rules (MVP)

An asymmetric two-player board game about the struggle between an **Empire** trying to hold territory and an **Insurgency** trying to take it. The two sides play by almost completely different rules: the Empire acts entirely in the open, moving physical troops; the Insurgency acts almost entirely in secret, seeding hidden cards across the map. The design goal is a *minimal* rule set — a small number of mechanics that interact to produce tension and bluffing, rather than realism.

Status: **implementable draft.** Every rule below is settled. The numbers in [Parameters](#parameters) are starting guesses and are expected to move; the rules are not.

---

## The Board

The board is a **network graph**:

- **Nodes** are towns (also read as cities or communities) — the places that get contested and scored.
- **Edges** are routes connecting towns — the only paths troops can move along.

Each town can hold a **face-down pile of cards** (the Insurgency's placed cards). The *height* of a pile is public information — everyone can see how many cards sit in a town. The *identity* of those cards is hidden until the town is resolved.

Once a town is **resolved**, it is frozen for the rest of the game: no more cards are placed there, no troops fight over it, and its outcome is fixed. Everything committed to it stays on the board face up, as a permanent public record.

There are no capitals. See [Decision 2](#2-troop-generation--one-per-turn-anywhere-the-empire-already-stands).

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

## The Pieces

**Insurgency**
- A **draw deck** containing two kinds of cards mixed together at a set ratio:
  - **Presence cards** — real presence, each worth 1 at resolution.
  - **Dummy cards** — blanks. Worth zero at resolution. Their only purpose is to disguise where real presence sits.
- Cards get placed face-down into town piles over the course of the game.

**Empire**
- **Troop pawns** — identical **Imperial Infantry** in the MVP. Each infantry has three defining numbers:
  - **Presence 3** — how much it counts for at resolution.
  - **Movement 1** — how many edges it can travel per turn.
  - **Peek 1** — how many hidden cards it can secretly look at when it holds still.

**Shared**
- **Resolved-town markers** to show a town is frozen and who won it.
- A **score track**.

---

## The Core Idea

- The **Insurgency** places cards secretly and can place them *anywhere* on the map. Because dummy cards are mixed in, the Empire can see a pile growing but never knows how much of it is real.
- The **Empire** plays *no cards at all*. It only moves troops (visible to everyone) and spends **looks** to peek at hidden piles. Its presence in a town is simply the troops standing there.
- A town is scored when someone calls for **resolution**. The pile flips, real presence is weighed against troop presence, and the higher total wins.
- **You only score points for what you capture from the opponent.** Taking an undefended town is worth nothing. The points come from beating a committed enemy.
- **Everything committed to a resolved town is spent permanently.** Both sides are playing from a finite budget, so the ideal outcome is winning a town by the narrowest possible margin against the largest possible enemy investment.

---

## Turn Structure

The **Insurgency takes the first turn.** Players then alternate. On a turn, a player does **everything** available to their side, rather than a single action.

Resolution comes first on both turns and is settled before anything else happens (Decision 4) — a phase of its own, not a decision held over until the end.

### Insurgency turn
1. Draw a full hand up to the **hand size**.
2. Optionally declare a **resolution** on one town where the Insurgency has at least one card. It resolves at once, on the pile as it already stands: this turn's cards are not down yet and do not count.
3. Place **the entire hand** face-down into unresolved town piles — any mix of presence and dummy cards, any number of towns, any number of cards into the same town. A town resolved in step 2 is closed and takes none of them.

### Empire turn
1. Optionally declare a **resolution** on one town where the Empire has at least one troop. It resolves at once, against the cards already standing there.
2. **Generate**: raise troops in any producing town the Empire **holds** — meaning it has troops there, *or* it won the town at a resolution. A garrison on the spot is not required, so a factory the Empire has already taken goes on building once the garrison marches off. An empty town nobody has taken builds for nobody. Supply does not limit this: overshooting the ceiling is legal and settled by attrition a turn later. The rebels *winning* the town stops it permanently.
3. **Move troops**: any or all troops may move up to their Movement in edges. Troops are not required to move. Resolved towns are ordinary terrain — pacified and passable, simply no longer contestable. A garrison that just *won* its town in step 1 may march straight out of it.
4. **Look**: any troop that did *not* move this turn may spend its Peek to secretly examine cards in its town.

---

## Resolution

Either player may declare one town resolved per turn, as a free action, on a town where **they have presence** — the Empire needs at least one troop there, the Insurgency at least one card in the pile. When a resolution is declared:

1. Flip the town's entire pile face-up. It stays face up for the rest of the game.
2. Total the **Insurgency presence** (sum of presence cards; dummies count zero).
3. Total the **Empire presence** (sum of the presence of all troops in that town).
4. The higher total **wins the town**. **The Empire wins ties.**
5. The town is frozen and marked with the winner.

### Scoring — capture only, winner take all

The winner scores points equal to **the opponent's committed presence in that town** — nothing more.

- **Insurgency wins** → scores points equal to the **Empire presence** that was present.
- **Empire wins** → scores points equal to the **Insurgency presence** that was present (real presence cards only; dummies are worth nothing).

Consequences that fall straight out of this one rule:
- Grabbing an empty or undefended town is worth **zero**. Walkovers don't pay.
- The bigger the enemy commitment you overcome, the bigger the score.
- A pile of dummy cards can bait the Empire into marching in a large garrison. If the Empire then wins the resolution, it captures only real presence — which was zero. A whole campaign spent on a phantom pays nothing. The bluff has teeth.

### After resolution

Everything in the town stays where it is, face up, for the rest of the game:

- **Cards** stay on the board face up, as a permanent public record of the fight. They score at the moment of the flip and do nothing afterward.
- **Troops committed to the town are spent.** They are removed from play entirely.

This is what makes commitment cost something. A resolved town is not a base, a garrison or an anchor — it is a hole in the ground where some of your army used to be.

Because commitment is permanent, waiting costs resources: the longer a town goes unresolved, the more both sides have sunk into it, and the more the eventual resolution consumes.

---

## End of the Game

The Insurgency deck is **finite and never reshuffles**. When it is exhausted and the Insurgency can no longer refill its hand, the game ends: **every remaining town anybody committed to resolves simultaneously**, by the normal rules, and all resulting points are scored. A town neither side ever set foot in — no troops, no cards — is left open instead. You may only resolve a town you are in (Decision 5), and that holds for the sweep as much as for a declared resolution: such a town is worth nothing to either side by definition, and handing it to whoever wins ties is noise on the board and in the log.

Whoever has the most points wins.

This means unresolved towns are never *safe* — only deferred. Declaring a resolution is not how you score; it is how you **lock in a result before the opponent can reverse it**.

There are three other ways the game ends, all of which do the same thing — resolve everything still standing, at once:

- **Every town is resolved.** There is nothing left to play for.
- **The Empire is eliminated**: no troops on the board, and no town left that will build it any. It can never act again, so the remaining turns would be the Insurgency placing cards nobody will contest. This costs the Insurgency nothing, because an undefended town is worth zero to it under capture-only scoring.
- **Both sides agree to stop.** Either player may put up a standing **offer to end**, and withdraw it again; when both offers are up, the game ends. This is *not* a pass in the turn-skipping sense — skipping a turn would stop the deck draining, and two cautious players could then stall forever, which is the failure this whole decision exists to prevent. It is an agreement, and it is not free: mass resolution settles every outstanding fight at the presence standing in it today, so agreeing to end is a real decision rather than a way of leaving the room. In a solo game one offer is enough, since the bot has no opinion to give.

---

## Parameters

The tunable knobs. These are starting values to playtest first, and all are expected to move.

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
