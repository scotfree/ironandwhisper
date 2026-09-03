# Iron and Whisper — Core Rules (MVP)

An asymmetric two-player board game about the struggle between an **Empire** trying to hold territory and an **Insurgency** trying to take it. The two sides play by almost completely different rules: the Empire acts entirely in the open, moving physical troops; the Insurgency acts almost entirely in secret, seeding hidden cards across the map. The design goal is a *minimal* rule set — a small number of mechanics that interact to produce tension and bluffing, rather than realism.

---

## The Board

The board is a **network graph**:

- **Nodes** are towns (also read as cities or communities) — the places that get contested and scored.
- **Edges** are routes connecting towns — the only paths troops can move along.
- Some towns are **capitals**, marked as such. Capitals are where the Empire generates new troops.

Each town can hold a **face-down pile of cards** (the Insurgency's placed cards). The *height* of a pile is public information — everyone can see how many cards sit in a town. The *identity* of those cards is hidden until the town is resolved.

Once a town is **resolved**, it is frozen for the rest of the game: no more cards are placed there, no troops fight over it, and its outcome is fixed.

## The Pieces

**Insurgency**
- A **draw deck** containing two kinds of cards mixed together at a set ratio:
  - **Influence cards** — real strength. Count toward the Insurgency's total at resolution.
  - **Dummy cards** — blanks. Worth zero at resolution. Their only purpose is to disguise where real influence sits.
- Cards get placed face-down into town piles over the course of the game.

**Empire**
- **Troop pawns** — identical **Imperial Infantry** in the MVP. Each infantry has three defining numbers:
  - **Strength 3** — how much it counts for at resolution.
  - **Movement 1** — how many edges it can travel per turn.
  - **Peek 1** — how many hidden cards it can secretly look at when it holds still.

**Shared**
- **Resolved-town markers** to show a town is frozen and who won it.
- A **score track**.

---

## The Core Idea

- The **Insurgency** places cards secretly and can place them *anywhere* on the map. Because dummy cards are mixed in, the Empire can see a pile growing but never knows how much of it is real.
- The **Empire** plays *no cards at all*. It only moves troops (visible to everyone) and spends **looks** to peek at hidden piles. Its strength in a town is simply the troops standing there.
- A town is scored when someone calls for **resolution**. The pile flips, real influence is weighed against troop strength, and the higher total wins.
- **You only score points for what you capture from the opponent.** Taking an undefended town is worth nothing. The points come from beating a committed enemy — which means both sides are constantly trying to bait the other into over-committing before springing a resolution.

---

## Turn Structure

The Empire takes the first turn. Players then alternate. On a turn, a player does **everything** available to their side (rather than a single action).

### Insurgency turn
1. Draw a full hand up to the **hand size**.
2. Place cards from hand face-down into any town piles (any mix of influence and dummy cards, any number of towns), subject to holding the whole hand — see Open Questions.
3. Optionally declare a **resolution** on a town (see below).

### Empire turn
1. **Generate troops**: each capital produces new troops at the generation rate. New troops appear at that capital.
2. **Move troops**: any or all troops may move up to their Movement in edges. Troops are not required to move.
3. **Look**: any troop that did *not* move may spend its Peek to secretly examine that many cards from the pile in its town. Looked-at cards are returned face-down (the look is a snapshot, not a permanent reveal).
4. Optionally declare a **resolution** on a town.

---

## Resolution

Either player may declare a town resolved. When they do:

1. Flip the town's entire pile face-up.
2. Total the **Insurgency influence** (sum of influence cards; dummies count zero).
3. Total the **Empire strength** (sum of the Strength of all troops in that town).
4. The higher total **wins the town**. (Tiebreaker: see Open Questions.)
5. The town is frozen and marked with the winner.

### Scoring — capture only, winner take all

The winner scores points equal to **the opponent's committed presence in that town** — nothing more.

- **Insurgency wins** → scores points equal to the **Empire strength** that was present (the troops it overcame).
- **Empire wins** → scores points equal to the **Insurgency influence** that was present (real influence cards only; dummies are worth nothing).

Consequences that fall straight out of this one rule:
- Grabbing an empty or undefended town is worth **zero**. Walkovers don't pay.
- The bigger the enemy commitment you overcome, the bigger the score — so both sides want to *let the pot build* before resolving.
- A pile of dummy cards can bait the Empire into marching in a large garrison. If the Empire then wins the resolution, it captures only real influence — which was zero. A whole campaign spent on a phantom pays nothing. The bluff has teeth.

---

## End of the Game

The game ends when **every town has been resolved**. Whoever has the most points wins.

---

## Parameters

The tunable knobs. Starting values are the ones to playtest first; all are expected to move.

| Parameter | Starting value | Notes |
|---|---|---|
| Rebel hand size | 5 | Cards drawn (and placed) per Insurgency turn. |
| Deck influence : dummy ratio | 50 : 50 | Composition of the Insurgency draw deck. |
| Rebel deck size | *TBD* | Whether the deck is finite matters — see Open Questions. |
| Infantry — Strength | 3 | Influence-equivalent at resolution. Deliberately higher than one card so troops are "heavy." |
| Infantry — Movement | 1 | Edges per turn. |
| Infantry — Peek | 1 | Cards a stationary troop may secretly view. |
| Troops per capital per turn | 1 | The Empire's growth rate / the game's clock. |
| Number of towns | *TBD* | Map size / density. |
| Number of capitals | *TBD* | More capitals = faster Empire growth. |
| Town point values | none (MVP) | MVP scores capture only; intrinsic town values are a deferred variant. |

---

## Open Questions

Things we should pin down before a first playtest:

1. **What forces the game forward?** Resolution is optional and there's no pass rule, so in principle two cautious players could stall with towns unresolved indefinitely. The likeliest fix is a **finite Insurgency deck** acting as a clock (when cards run low, the pressure to resolve becomes real) — but we need to decide whether the deck is finite, whether it reshuffles, and what happens when it's exhausted.
2. **Is declaring a resolution part of your turn, or your whole turn?** And may you resolve more than one town in a single turn? This meaningfully changes tempo.
3. **Must the Insurgency place its entire hand each turn?** MVP assumption is yes (fully "stateless" — draw, place all, done), which means an all-dummy draw must still hit the board. Allowing players to hold or discard cards is the obvious variant, but it changes the information the Empire reads from pile growth.
4. **Resolution tiebreaker.** Minor, but needed. Options: Empire wins ties (fits the "entrenched defender" theme), ties split or score nothing, or ties go to whoever *didn't* call the resolution.
5. **Starting setup.** Where do Empire troops begin, how many, how many towns and capitals, and does the board start with any cards on it? Needs a concrete opening position.
6. **Look mechanics, fine print.** Confirm that a look is a private snapshot returned face-down, that multiple stationary troops in one town stack their Peek, and whether the Empire may look at a town in the same turn it garrisons it.

---

## Next Steps — Deferred Richness

Explicitly **out of the MVP**. These are the directions we liked and want room to grow into once the core loop is proven fun. Each should be a modification of the simple rules above, not a replacement.

**Richer cards** (revealed at resolution alongside plain influence):
- A card worth *extra* influence.
- A card that *doubles* the town's stakes — a way to gamble on a contested town.
- A card that *resets* a town (clears the pile) without resolving it.
- A card that *delays* resolution — the town doesn't lock even though someone called it.

**Richer Empire units** (the Empire trades card-play flexibility for unit variety):
- **Scouts** — fast movement, low strength.
- **Political agents** — troops that grant extra looks per action.
- Generally, new units defined by trading among Strength / Movement / Peek.

**Information mechanics** (letting the Empire partially pierce the fog):
- The Empire occasionally gets to see the Insurgency's hand before placement, or learn *how many* real cards are in it, or the current real/dummy ratio — softening the pure guessing game.

**Scoring / map variants:**
- Intrinsic town values (small towns vs. cities) so *where* you fight matters, not just how hard.
- Capitals carrying special meaning for the Insurgency too, to preserve symmetry.

**Flow variants:**
- A Go-style **mutual pass**: if both players pass, flip and resolve all remaining towns at once.
