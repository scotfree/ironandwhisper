# Iron and Whispers — project notes

The game is **displayed** as "Iron and Whispers". Every identifier is still
`ironandwhisper` — the repo, the BGA project, the PHP namespace, the remote directory —
and should stay that way. Only `gameinfos.jsonc`'s `game_name` carries the plural.

Asymmetric two-player board game for Board Game Arena. Empire moves visible troops;
Insurgency seeds hidden cards. See `ironandwhisper.md` for the full rules and the
reasoning behind every decision.

---

## CRITICAL

**Presence is the one quantity, and the vocabulary is settled** (2026-09-13). Both sides
accumulate **presence** in a town and the higher total wins; say **card presence** and
**troop presence** when the source matters. *Influence* and *strength* are gone — they were
two names for one thing in the exact place a player has to compare them. Cards are
**agents** (`Agent +0` is a decoy). The old boolean sense of "presence" — the Decision 5
gate — is replaced by **occupied** (troops there), **seeded** (cards there) and
**controlled** (resolved and won), because it was never clear which of the three it meant.
Card type ids stay `influence0`…`influence3`: arbitrary, unseen, and not worth churning
every scenario file for. See *Vocabulary* in `ironandwhisper.md`.


**The rules are settled and encoded in `sim/`. Port from the simulator, not from memory.**
`sim/engine.py` is the executable specification and `sim/test_engine.py` has 50 tests, each
named for the design decision it pins down. If the PHP disagrees with the simulator, the
PHP is wrong. `tests/test_rules.php` mirrors those cases in PHP — when you change a rule,
change it in both places and in `ironandwhisper.md`.

**The loser's commitment is taken; the winner's stays** (Decision 3). The Empire keeps its
garrison in a town it wins, and that garrison keeps carrying supply. This is *not* the old
"troops always survive" arrangement that measured at 99.7% Empire wins — what reopens the
Insurgency's scoring is that Empire troops are still removed when it *loses*, and that
cutting supply starves them without a fight at all. Do not restore the winner-keeps-all
version, and do not make attrition score nothing.

**Attrition waits a turn, and the wait is the point** (Decision 2). A network that
cannot feed its troops marks them; only if it is *still* short at the end of the Empire's
next turn do they starve. Immediate attrition made massing self-defeating invisibly —
supply comes from towns the Empire occupies, so concentrating an army destroys the supply
that fed it, and the loss landed inside the commit where nobody was looking. The mark is a
**forecast, recomputed when it falls**, so repairing the line clears it; do not turn it
into a reservation of particular troops. There are no troop objects to reserve anyway —
`iaw_town.troops` is an integer, and `attritionPlan` chooses only which *towns* pay,
largest garrison first.

**Supply is a ceiling on what a network can *keep*, not income and not a cap on building**
(Decision 2). Networks of Empire-occupied towns pool their towns' supply; that divided by
`supply_per_troop` is the most troops the network can keep standing, and anything over is
marked and then starves. Building past it is legal — `validateProduction` checks presence
and the town's own rate and nothing else — which is what lets the Empire raise troops and
march them out to the supply that will feed them in one turn. Do not put the ceiling back
into production: it was one word doing two jobs, and it left a network at its ceiling with
an idle factory. Production is a separate per-town
number. The two are independent on purpose — a poor town can be a depot, a rich one can
build nothing. An earlier design had the network contribute *attack presence* instead;
it fails, and `ironandwhisper.md` Decision 2 records why.

**Resolution happens first in a turn, and is judged on the board as your opponent left
it** (Decision 4). It used to be last, which made every resolution risk-free: the Empire
marched a troop in and took the town on arrival, the Insurgency placed exactly enough and
cashed it in the same breath. Do not move it back.

**Resolution is its own phase and its own action, applied immediately** — the `Resolve`
state (12), which both sides pass through before their turn proper whenever they have
anything resolvable. It was once staged with the rest of the turn and sent in the same
action, and the client then had to guess at its own outcome: it forbade placing into the
town being resolved and forbade marching out of it, because it could not know whether the
garrison would survive. Resolving first *and separately* removes both restrictions — a
garrison that wins its town may march straight back out of it. Do not fold it back into
`actCommitTurn`: that would also reopen resolving twice in one turn, which the phase
prevents structurally.

**This BGA skeleton is a framework generation newer than zoomquest's.** See the section
below before assuming anything carries over. zoomquest is a useful reference for *shape*
but its framework idioms are obsolete.

**`data/`, `maps/` and `scenarios/` are shared config, read by both the simulator and the
PHP.** Changing their shape means changing both. That sharing is the whole reason the
tuning work transfers.

**`baseline` is currently set for feel, not for balance.** Cards are 0 or 1, a troop is
presence 1 and costs 1 supply, every town supplies 2 — deliberately minimal, at the
player's request, so the shape of the game can be felt. **Hand size is 3** (was 5, changed
2026-09-11): a five-card hand lets the rebels rush the Empire's starting city before it can
stand anything up, which real play found and the bots do not, and placing five cards a turn
is a chore. It makes the game 20 turns rather than 12 — the deck is the clock, so
`turns = deck_size / hand_size`. A sweep of hand 3-6 found the Empire monotonically better
off with a smaller hand, in two framings (deck held at 60, and deck scaled to hold the game
at 10 turns), so the effect survives controlling for game length. The mechanism is *not* the number of towns the
Empire wins — that is flat at about 4 of 12 whatever the hand size. What changes is what
those towns are worth: at hand 6 the Empire captures 0.1% of the rebels' presence, at hand
3 it captures 4.8%. A big hand buries every town under a pile no garrison can match, so
anything the Empire wins is something the rebels did not bother contesting. At those
numbers the Empire wins **0.6%** against the bots.

**Everlan starts with 3 troops rather than 2** (2026-09-12). Not a balance number but a
threshold one: at presence 1, against a hand of three cards worth at most 1 each, a
garrison of 2 loses Everlan to the opening placement and a garrison of 3 *ties* it — and
the Empire wins ties. The rush now takes the rebels two turns, and the Empire gets one in
between. **This lever is spent at 4 troops in total.** Everlan and Belmar supply 2 each, so
the network ceiling is exactly 4; a fifth starting troop is over supply on turn one,
starves, and scores for the rebels. A sweep has the Insurgency's mean score going
8.7 → 13.2 → 16.1 as the garrison goes 3 → 4 → 6, while the Empire's win rate stays flat
inside noise. More help has to come from troop presence or from supply, not from more
starting troops.

> An earlier reading of this sweep had the Empire taking 10.3 of 12 towns at hand 6 and
> concluded it was board-shrinking into empty towns. That column was an artefact: the
> end-of-game sweep was handing every *uncontested* town to the Empire on the tie-break.
> Once the sweep started leaving those open the figure dropped to ~4 and went flat. The
> direction of the hand-size finding was unaffected, but the story about why was wrong —
> which is the usual lesson about measuring a mechanic inside a broken configuration. Do not read anything into a game played on them, and do not "fix"
them without asking: the simplification is deliberate. The last roughly-even settings —
graded cards, heterogeneous map, presence 3 — are in the git history at `dedba1a`.

**Why 0% is the ordering change working rather than failing.** With cards worth at most 1,
public pile height is an exact upper bound on a town's presence, and ties go to the Empire,
so N troops beat any N-card pile *with certainty*. The Empire's whole game at these
parameters was the guaranteed snipe: march one troop in, resolve on arrival, take the town
and its supply for free. Resolution-first removes it and leaves the Empire nothing. The
two things that give it a game back are parameter edits, not code: **graded cards** (a
lone-troop attack becomes a bet on whether that card is a 3) and **troop presence above 1**.
Graded cards are also what make concealment mean anything at all — with a maximum of 1,
peeking can only tell you a pile is worth less than you already knew it could not exceed.

**Troop presence against card value is the lever, not the Insurgency's economy.** Cutting
presence from 36 to 18 made the Empire *worse* (2% to 0.3%), because the Empire scores by
capturing presence — a poorer Insurgency is a smaller prize. What moves it is how much a
garrison is worth: at presence 1 a lone troop is beaten by two cards, at presence 3 it
takes four. Every parameter sweep so far has found a **cliff** rather than a curve, in the
same place each time — where the bots' strategy flips — so treat single measurements
either side of it with suspicion.

**Balance was, before the simplification, roughly even and entirely untested by humans.** 500 bot games put the Empire
at 49% in Python and 52% in PHP. Getting there needed a *heterogeneous* map: balance sits
on a cliff between 2.5 and 3.0 supply-troops per ordinary town, and a uniform map lands on
one side or the other. Raising the capitals instead moves it the wrong way — concentrated
supply makes the Empire fragile. Assume the cliff is where the bots' strategy flips rather
than a real property of the game, and re-tune after any rules change.

**The control scenarios are stale.** `flat`, `graded36`, `blind` and friends were measured
before supply, production and denial existed. They still load and run; their recorded
numbers in `ironandwhisper.md` belong to the rules of the time.

**There is no "dummy" card any more.** Card type ids are `influence0` through `influence3`
and carry their own value. A bluff is a card worth 0.

---

## Current state

**Deployed, playable on the Studio, and being played.** The rules have moved a long way
since the first port; several sessions of real play have driven that.

Done:
- BGA Studio project `ironandwhisper`, deploying cleanly over SFTP with a client build.
- Full rules simulator, bots, 85 tests, an exploration notebook, and a batch runner.
- **The PHP port**: `dbmodel.sql`, `Scenario`, `Rules`, `Bots`, `Board`, `View`, `Game`,
  and the game states. See *How the port is put together* below.
- **TypeScript client**: board from the map JSON, drag-and-drop placement, staged turns,
  supply and network drawn on the board, a log with a line per action. A town draws its
  cards as **two stacks** — face down with a height, face up with a height and the presence
  they total, individual values on the tooltip. Laying every card out made a well-seeded
  town enormous, and the row's only readable property was its length. The Insurgency is
  still *sent* its own face-down cards and is simply not shown them: once a card is down it
  is down, and remembering the board is part of the game. Town boxes are a **fixed
  120x104** whatever they hold, framed by an SVG silhouette — `img/town.svg` for an
  ordinary town, `img/city.svg` for a producer — fetched at setup and inlined. The files
  are editable in a drawing app; both put the *shoulder*, where the roof meets the walls,
  at y = 26 of 104, and `.iaw-town`'s top padding depends on that. **The frames are
  recoloured by rewriting the markup, not by CSS** (`BoardView.frameMarkup`): a drawing app
  writes colour as an inline `style`, which beats any stylesheet rule, so the CSS approach
  worked only for the hand-written first drafts that used presentation attributes. The
  convention is that **white and black are the game's colours** — they become the fill and
  stroke of whoever holds the town — and any other colour is left alone, so detail lines
  and gradients survive. `img/README` says this to whoever opens the files, and the same
  convention drives `img/pawn.svg`, the Empire troop marker, which resolves white and black
  to the Empire's purple instead. Every log line is prefixed `T${turn}:` from inside its
  `clienttranslate` literal.
- **The town box reads spatially**: rebels down the left (face-down stack above face-up),
  Empire down the right (pawn and count, then supply over the town's own contribution),
  production mark beside the name. Which side a number belongs to is legible from where it
  sits before the number is read. **A staged change reads the same on both sides** —
  `.iaw-troop-delta` and `.iaw-card-delta` share one rule and differ only in colour, each
  sitting beside the number it changes. **With one deliberate exception**
  (2026-09-14): `.iaw-build-delta`, the troops about to be raised, is pulsing Empire
  purple at 14px. A build is the only change the Empire *creates* rather than moves, and
  it is the step being decided while it is on screen. It is also tracked apart from
  `troopDelta` because the two used to be summed — a town raising one troop while three
  marched out read "-2", and the choice just made vanished into the arithmetic. The rebel marker used to be `.iaw-town-pending`, a
  grey strip across the bottom of the box with no styling attached to its `pending` class;
  a real game found that nobody could see where their cards were going. The Insurgency's
  turn box now also lists one line per staged card, in placement order — "+2" says how many
  and not which, and which is the whole decision; the order matters too, since the last card
  onto a town is the top of its pile and the first thing a look reads.
- **`MINIMAL_TOWNS` in `BoardView.ts` hides the Empire's supply arithmetic in the town
  boxes** (2026-09-14, currently on): the network badge and the town's own contribution,
  the two lines a real game found nobody was reading. A build-time constant rather than a
  game preference — it exists so the fuller version comes back in one line, not because it
  is a choice to make often. Nothing it hides is unavailable: the army list carries each
  network's numbers and still turns red over the ceiling, and troops under notice still
  pulse with what they are about to lose. Revisit if a second display option ever makes
  simple-versus-full a real choice for players.
- **An army list beside the board**, one entry per Empire supply network: a large pawn, the
  network named for the town holding most of it, and "N troops use N supply of M
  available." It turns red when the army is over its ceiling. The point is the *split* — a
  cut line is the most consequential thing that happens to the Empire and was otherwise
  legible only by comparing twelve supply badges. The name breaks ties by hashing the
  network's membership, so it is arbitrary rather than alphabetical, stable while the army
  is, and reshuffles when the army changes; randomising per render would make it unreadable.
- **The side column reads top to bottom in the order you need it**: the game state
  (turn / deck / hand, and the numbered turn order with the live step lit), the zoomed
  card, the turn summary with the Insurgency's hand inside it, the armies, what the
  opponent did last turn, and a permanent reminder of what your side does. The cards and
  the list of where they are going are one decision, so they share a frame; the Empire has
  no hand frame at all and reads the rebels' card count off the state box.
- **The phase list is numbered explicitly** (`list-style: decimal`). It was already an
  `<ol>` and rendered without numbers, because BGA's reset strips list markers. It is
  separate from the prose primer so the two can be hidden independently — the phase list
  changes constantly and belongs with the counters, the primer never changes.
- **A card you pick up says so.** Clicking a hand card sets `selectedCard`, which was never
  passed to `renderHand`, so selection changed nothing on screen and players reported that
  click-then-click "did not work". It always worked; it just said nothing. Selection now
  outlines the card and draws it large in `#iaw-zoom`, with Escape to drop it.
- **Both stacks on a town are clickable** and open the pile in order, top first. The rebels
  see the total of their own face-down pile and the Empire sees `?` — saying what is unknown
  beats leaving a gap. This reversed a decision: memory was judged clerical rather than
  strategic, since the player who keeps notes should not beat the player who does not. The
  server always sent the Insurgency its own faces (`View::pileView`); only the client
  declined to draw them, so nothing about hidden information changed.
- **What the opponent just did persists rather than animating.** Ghost arrows on the roads
  they marched, their agents greyed above the towns they landed in, and the same lines
  their own staging panel showed them, in `#iaw-last-turn`. An animation plays once and is
  gone, and in a turn-based game you often arrive after it played — the instinct to add a
  "show again" button is the tell that the information should not have been ephemeral.
  Animation is deferred to issue #4 as polish, not as a carrier of information.
- **Staged agents are drawn face up straddling the bottom edge of their target town**, on
  `#iaw-overlays` rather than inside the town box: `.iaw-town` is a fixed 120x104 with
  `overflow: hidden`, so anything drawn in the box is clipped. Half in and half out reads as
  *arriving* rather than as part of the town, and clears the bottom row, which carries the
  face-up stack and the supply contribution. Only live staging pulses; last turn's cards are
  history and sit still on the same layer, greyed. Each chip carries a tooltip saying which
  of the two it is, which needed `pointer-events: auto` on the chips — the layer itself
  ignores the mouse. That costs a small dead spot on the town's bottom edge.
- **The zoomed card floats over the side column rather than sitting in it.** As a frame it
  shoved the turn summary and the armies down the page every time a card was clicked. It is
  deliberately *not* a BGA popin: a popin dims and blocks the board, and selecting a card
  then clicking a town is the whole interaction — the popin would block the second half of
  it. Closing runs an `onDismiss` callback, so a card opened by selecting it is also put
  down, while a card opened to be looked at has nothing to undo. On a narrow screen it has
  nowhere sensible to go; that is deferred with the rest of the mobile question (issue #17).
  It is vertically centred on the column, bordered in heavy black with rounded corners so it
  reads as a *card* rather than another frame, and **only the side column dims behind it** —
  the board stays bright and clickable, because select-a-card-then-click-a-town is the whole
  interaction.
- **Most specific target wins inside a town box**: stack, then garrison, then the town. The
  cost is that the garrison's pixels stop being a build or march target, and that is the
  right trade — opening a card you did not want costs a dismissal, taking an action you did
  not want can cost the whole turn.
- **A BGA popin's close button destroys its DOM**, which is what `replaceCloseCallback`
  exists for. Both dialogs kept one instance and so opened exactly once, then silently did
  nothing. They are rebuilt on every open instead. Anything using `ebg.popindialog` needs
  this.
- **A `?` at the right-hand end of the title bar opens the cheat sheet** (`src/ts/Help.ts`),
  four paragraphs and a legend of every icon. It is **not** a status bar action button:
  `removeActionButtons()` runs on every state change and would take it with it, so it lives
  in `#iaw-help-corner`, added once to `#page-title`. The legend draws the *real* components
  — the silhouettes fetched from `img/`, the pawn, the same stack and badge markup the board
  uses — so it cannot drift from what is on screen, and every number in the text comes from
  the scenario. It links out to `ironandwhisper.md` on GitHub, since `*.md` is excluded from
  the deploy and the rules are not on the BGA server.
- `#iaw-table` is `flex-wrap: nowrap`. It wrapped, which silently dropped the whole side
  column — turn state, armies, hand — below the board whenever the play area was narrow.
- **115 PHP tests** against SQLite, plus `tests/selfplay.php` for cross-engine comparison.
- **Heuristic bots** on both sides, and a solo game against one.
- **`GlobEmpire`, the bot that plays the way the game is played well** (`sim/bots.py`,
  `Bots::globEmpireTurn`), and game option 101 to choose between it and the heuristic bot
  in a solo game. It beats the heuristic Insurgency **64%** of the time where the old
  Empire bot managed 0.5%. See *The Empire bot that works* below.
- **`MistBot`, the same treatment for the rebels** (`sim/bots.py`, `Bots::mistInsurgencyTurn`),
  and game option 102 to choose between it and the heuristic rebels in a solo game. It
  takes **100% of 300 games** off `GlobEmpire` at baseline, mean score 5.8 to 0.2. See
  *The rebel bot that works* below — and read every Empire number above it as a statement
  about the opponent it was measured against.

Not done, in rough order of how much it hurts:

- **Attrition losses are chosen server-side.** The `disband` argument exists and the rules
  honour it, but the client sends an empty one, so losses come off the largest garrisons.
  Choosing badly can sever a second line, so this is a real decision going unmade. Now that
  the forecast is *shown* a turn ahead, the case for letting a player redirect it is
  stronger. `disband` names a per-town cap; the PHP's cap was unreachable dead code until
  the grace turn made the plan visible, and is now fixed and matched to the simulator.
- **The *heuristic* Empire bot does not understand supply when marching.** `empireMoves`
  marches toward attractive piles without checking what abandoning a town does to the
  ceiling, so it routinely walks itself into starvation and donates the points. `GlobEmpire`
  was written because of this and does not share the fault; the heuristic bot is kept as the
  port's reference implementation and as the weaker opponent.
- **The Insurgency bot has now had the same treatment, and it reversed the scoreboard.**
  `GlobEmpire`'s 64% was against the heuristic rebels; against `MistBot` it wins nothing.
  The old bot is kept as the weaker opponent and as the port's reference implementation,
  but every figure in this file measured against it is "how well does the Empire do
  against a mediocre rebel", not balance.
- **The Empire is losing badly at the table, and the bots now agree.** `GlobEmpire`
  winning 64% said more about the heuristic rebels than about the Empire: against
  `MistBot` it takes 0%, which is what real play has been saying all along. Hand size and
  the starting garrison are both spent as levers (see the notes above each). The next
  moves are still **graded cards** and **troop presence above 1** — open question 2, and
  there is now a rebel bot good enough to measure them against.
- **`GlobEmpire`'s certainty test does not survive graded cards.** It resolves when troops
  beat `revealed + pile height x the best card in the deck`, which is exact at baseline
  where cards are 0 or 1 and useless at `graded36`, where every face-down card is assumed to
  be a 3 and almost nothing is ever certain. It plays legally there and wins 0%. Putting
  graded cards back means giving it a quantile or expected-value test instead.
- No stats in `stats.jsonc`, no tie-breaker, no animations, no art.

**The turn counter was frozen at page load** until 2026-09-12. The round advances at the
end of the Empire's turn (`Game::incRound`) and nothing told the client; `notif_deckCount`
re-rendered the clock with `gamedatas.round` from setup. The round now rides along with the
deck count. Worth remembering as a shape: anything the client caches from `getAllDatas` and
never hears about again will be wrong for the rest of the game.

**A GPT that plays the Insurgency lives on the `gpt-experiment` branch**, in `gpt/`, parked
2026-09-14. Not part of the game — nothing in `sim/` or `modules/` imports it and
`tools/deploy.json` excludes it on both branches. Karpathy's ~1,500-parameter
dependency-free GPT, cloned from MistBot and then improved with policy gradient, reaches
+5.33 margin against `GlobEmpire` where MistBot gets +5.57; the same reinforcement budget
from random init reaches −0.12, worse than a random rebel. The finding worth carrying
back to any future bot work is that reward alone farmed the *weakest* opponent in the
pool. `gpt/RESULTS.md` has the tables. The trained weights are gitignored and exist only
on the machine that made them.

**Deferred work lives in GitHub Issues**, not in this file:
https://github.com/scotfree/ironandwhisper/issues, labelled `design`, `balance`, `ui`,
`bots`, `playtest`, `deferred`. This document and `ironandwhisper.md` record decisions and
the reasoning behind them; the tracker holds what has not been done. An issue here carries
the *why* — why it is parked, what it would cost, what it interacts with — in the same
voice as the design doc, because that is what makes it worth reading a month later.

**The Studio lobby shows the project name, and that is correct** (issue #16, closed
2026-09-13). Studio lists games by folder name — `ironandwhisper` — and `game_name` does not
drive that list. BGA staff on the question: *"When a game is first released to Alpha, the
name will appear as `<gamename>_displayed` for the first 24 hours or so, but after that
point it will be updated by the translation system"*
([forum 21424](https://forum.boardgamearena.com/viewtopic.php?t=21424)); the same thread
says not to worry about the Studio name, which is not public-facing. **The real name arrives
via the translation system at alpha.** Do not ask support to rename anything. The `name`
field in the metadata manager's Game infos tab is the internal identifier, not a display
field.

**Text metadata has moved out of `gameinfos.jsonc` and some fields there are now ignored.**
The metadata manager owns it, and "Reload game informations" warns about deprecated fields.
Ours has never been audited against that warning — see issue #15.

## Deploying

```bash
./tools/deploy.sh              # build the client, then sync changed files
./tools/deploy.sh --dry-run    # show what would transfer (skips the build)
./tools/deploy.sh --watch      # re-sync on save; run `npm run watch` alongside
./tools/deploy.sh --delete     # prune server files that no longer exist locally
./tools/deploy.sh --no-build   # skip the client build, for PHP-only changes
```

**The first deploy of the port must use `--delete`.** The skeleton's `PlayerTurn.php` and
`NextPlayer.php` are still on the server, and BGA discovers state classes by scanning
`modules/php/States/`. They declare state ids 10 and 90, which are now `InsurgencyTurn`
and `NextTurn` — leaving them there means duplicate state ids.

`./tools/deploy.sh` runs `npm run build` first, because `src/` is excluded from the upload
and what actually ships is the compiled `modules/js/Game.js` and `ironandwhisper.css`.

- Host `1.studio.boardgamearena.com`, port **2022**, user `scotfree`, remote dir
  `/ironandwhisper`.
- **Password lives in the macOS Keychain**, service `bga-studio-sftp`, account `scotfree`.
  Never in a file. `tools/deploy.py` reads it via `security find-generic-password`.
- Config and exclude list: `tools/deploy.json`. Only the game files and the shared JSON
  reach BGA — `sim/`, `tests/`, `notebooks/`, `tools/`, `misc/`, `src/` and `*.md` are excluded.
- First run creates `tools/.venv` (paramiko) automatically.

Password auth was chosen over an SSH key deliberately, for now. Uploading a key to the
Studio control panel permanently disables password auth on the account — worth doing
eventually, since it would let the deploy script drop Python and paramiko entirely for
about fifteen lines of shell around `sftp -b`.

---

## The framework generation gotcha

This skeleton differs from zoomquest in ways that will bite:

| zoomquest | this project |
|---|---|
| `gameinfos.inc.php` | `gameinfos.jsonc` (also gameoptions, gamepreferences, stats) |
| `zoomquest.game.php` | **gone** |
| `zoomquest.view.php` | **gone** |
| `zoomquest_zoomquest.tpl` | **gone** — DOM is built client-side |
| loosely typed PHP | namespaced PHP 8, `declare(strict_types=1)` |
| transition strings | state classes returned by class name |

Specifics:
- Namespace is `Bga\Games\IronAndWhisper`; `Game extends \Bga\GameFramework\Table`.
- State classes live in `modules/php/States/`, extend `GameState`, declare `id:` and
  `type:` via constructor named arguments, mark handlers `#[PossibleAction]`, and
  **return the next state's class** (`return NextTurn::class;`).
- `setupNewGame()` returns the starting state class.
- Services are injected: `$this->bga->notify`, `->playerScore`, `->counterFactory`,
  `->debug`.
- `bga-framework.d.ts` (58KB) ships in the repo — real type definitions for the client
  API, which is otherwise thinly documented. The main practical argument for TypeScript.
- `src/` holds the client source; `npm run build` compiles TS → `modules/js/Game.js` and
  SCSS → `ironandwhisper.css`. **Never edit those two by hand** — `tools/deploy.sh` rebuilds
  them before every sync, so edits there are silently overwritten.
- `misc/` is BGA's designated non-deployed directory.

---

## Repo layout

```
ironandwhisper.md      rules + Decisions & Constraints (the source of truth for design)
data/units.json        presence / movement / peek per unit type
data/cards.json        presence value per card type
maps/*.json            geography: towns with x/y, edges
scenarios/*.json       references a map, sets the knobs. `baseline` is the game;
                       `flat`, `graded36`, `blind`, `flat_blind`, `graded36_blind`
                       are controls, kept so the measurements can be re-run
sim/                   Python rules engine, bots, tests, human play interface
tests/                 PHP tests, and a stub of the BGA framework to run them against
notebooks/             exploration.ipynb + build_notebook.py that generates it
tools/                 deploy script and config
modules/php/           BGA game logic
modules/js/Game.js     compiled client — build output, do not edit
src/ts, src/scss       client source
```

Map and scenario are split so the same board can run at many parameter settings without
duplicating the graph.

## The simulator

```bash
sim/.venv/bin/python -m pytest sim -q            # 85 tests
sim/.venv/bin/python -m sim.run --games 500      # batch runner
sim/.venv/bin/python -m sim.run --games 500 --bots glob   # the good Empire bot
sim/.venv/bin/python -m sim.run --games 500 --bots glob --insurgency mist   # both good bots
sim/.venv/bin/jupyter notebook notebooks/exploration.ipynb
```

`sim/play.py` gives a `Table` you can drive by hand from a notebook or REPL, playing either
side or both, with the board rendered per-player so hidden information stays hidden. Useful
for checking the PHP against a known-good position.

Two venvs on purpose: `tools/.venv` is paramiko only and stays light because deploy runs
constantly; `sim/.venv` carries the scientific stack.

---

## How the port is put together

The layering exists so the rules can be tested without a database and the hidden
information has exactly one gate.

| file | job | knows about |
|---|---|---|
| `modules/php/Rules.php` | the rules, as pure functions over plain arrays | nothing |
| `modules/php/Bots.php` | the heuristics, also pure | Rules and Scenario |
| `modules/php/BoardView.ts` | drawing only; computes networks from the board itself | nothing server-side |
| `modules/php/Scenario.php` | loads `data/`, `maps/`, `scenarios/` | the JSON |
| `modules/php/Board.php` | every read and write of `iaw_town` and `iaw_card` | the database |
| `modules/php/View.php` | what each side may see | Rules' shape |
| `modules/php/Game.php` | setup, sides, scoring, `getAllDatas` | all of the above |
| `modules/php/States/` | turn structure and notifications | all of the above |

`Rules.php` is a direct port of the pure logic in `sim/engine.py`. It returns *plans* —
`planMoves` gives departures and arrivals, `peekPlan` gives look counts, `revealFromPile`
names the cards to turn over — and the state classes persist them. If the two disagree, the PHP is
wrong.

**Turn application lives in `Game`, not in the state classes.** `applyInsurgencyTurn` and
`applyEmpireTurn` do the work; the state classes are thin adapters that call them and
return `NextTurn::class`. That is deliberate: a bot takes its turn by calling the same
methods, so it is held to the same validation and emits the same notifications as a person.
It also sidesteps the question of whether a hand-constructed state object gets the
framework's services injected — it does not have to, because nothing hand-constructs one.

**The state machine** is four states plus the framework's own:

- `Resolve` (12) — `actResolve(town)` or `actSkipResolve()`. Entered from `NextTurn` before
  either turn state, and only when `Rules::legalResolutions` finds something: a state whose
  only legal answer is "no" is a click for nothing. It applies the resolution at once and
  returns the mover's turn state — or `NextTurn`, if that was the last open town.
  The Empire is offered this phase nearly every turn, because a garrison can always close
  the town it stands in, empty or not; that is board-shrinking, and it is a real move.
- `InsurgencyTurn` (10) — `actCommitTurn(placements)`. The whole hand in one action,
  because placement is one simultaneous decision.
- `EmpireTurn` (11) — `actCommitTurn(produce, moves, disband)`. Building, marching,
  automatic looking, and attrition last.
- `NextTurn` (90) — upkeep: refill the hand, detect the end, hand over to the other side.
  This is `prepare_turn()`; it runs *before* a player is asked for anything, which is why
  the hand refill and the end of the game both live here. **A bot's turn happens inside
  this state**, in a loop: only a human needs a state of their own, because only a human
  has to be asked.

`Game::toMove()` is a global mirroring `GameState.to_move`, set by each turn state before
it returns to `NextTurn`. Seat order does not decide who starts; the scenario does.

**Data model.** Town geography is never stored — ids, labels, coordinates and adjacency
come from the map JSON. `iaw_card` carries `card_location` (`deck`, `hand`, or
`town:<id>`), `location_order` (**0 is the top of a pile**), and `empire_seen`.

`empire_seen` is the whole face-down/face-up distinction: a town's *pile* is its cards with
`empire_seen = 0` in `location_order`, and its *revealed* area is the rest. Looking sets the
flag; resolution sets it for everything in the town at once. BGA's `Deck` component is not
used — it models a deck plus hands, and this needs a dozen ordered piles with stable card
identity.

**Hidden information** goes through `View::forSide` and nowhere else. Only the face-down
pile is secret:

- The **Insurgency** placed every card, so it sees everything, face down or not.
- **Everyone** sees the face-up cards beside a town and the *height* of the face-down pile.
  That is why `cardsRevealed` is a `notify->all` carrying real faces, and why it is correct.
- A **spectator** is simply somebody with no hand and no face-down vision.

Card **ids** are public: they reveal nothing about type, the Empire already gets them from
`getAllDatas`, and the client needs them to match a card turned over to one on screen. The
public placement notification therefore carries ids and no types; the Insurgency's client
fills the faces in from the hand it already holds.

## The Empire bot that works

`GlobEmpire` in `sim/bots.py`, ported to `Bots::globEmpireTurn`. It is not a cleverer
search than the heuristic bot — it is a *different objective*. The heuristic bot plays for
points and marches at the tallest pile it can see; this one plays for **ceiling**, and
collects points as a by-product of resolving towns it was already safe in. It is a
transcription of how the game is actually played well at a table, and it takes the Empire
from 0.5% against the Insurgency bot to **64%**.

The rules, in the order the turn applies them:

1. **Resolve only a certain win.** `worstCase = revealed presence + pile height x the best
   card in the deck`. Resolve if `troop presence >= worstCase`, ties included, and never
   otherwise. Peeked cards need no special handling — a look moves a card face up, so it is
   already in `revealed` and already counted exactly.
2. **Richest certain win first**, tie-broken toward a production town, whose ownership
   survives the garrison marching away.
3. **An empty town is a certain win worth nothing, and worth taking anyway**: it locks the
   ground, its supply and its production for the rest of the game.
4. **Expand in a wave** into every free adjacent town the spare troops can certainly hold,
   preferring a *seeded* town to an empty one — same supply, and the presence is free. One
   move at a time, re-deriving the options after each, since every move changes the network.
5. **Rescue or withdraw, never dribble.** A garrison behind by `GLOB_RETREAT_MARGIN` or more
   is either reinforced to a certain win in one motion or pulled out entirely. Half a relief
   column loses the column as well as the town.
6. **Build to the ceiling the march is about to create.** The march is planned *before*
   production is chosen, so "you may build past the ceiling if you will reach the supply
   this turn" needs no special case — by then the new ceiling is a fact.
7. **Name attrition losses that do not cut the line** in `disband`. It is the first code
   anywhere to use that argument.

Two things about it worth keeping:

**The retreat margin is large, and that is not a fudge.** `worstCase` assumes every
face-down card is the best in the deck, and the baseline deck is 40% bluffs, so a pile that
*could* beat a garrison by two usually does not. A sweep over 500 games per setting puts the
Empire at 44% at a margin of 3, 60% at 4, 64% at 5, 66% at 6, 59% at 8 and 46% at 14 — a
broad plateau, not the cliffs these sweeps usually find. The default is 5. Note the
tradeoff: a *small* margin makes for a livelier game — at 3 it is 44%/44% with both sides
scoring around 3.1, where at 5 the Empire wins more but both scores fall and one game in
five is a draw. The bot wins partly by shrinking the board.

**The port is checked position by position, not just statistically.** 300 random boards run
through both engines produce byte-identical resolve, produce, moves and disband. That is a
stronger check than `selfplay.php` can give and it is how the two were reconciled: at 1000
games the win rates looked 4 points apart, and at 3000 they agree (PHP 63.1% / 19.6% / 17.2%
against the simulator's 64.1% / 18.5% / 17.4%, mean scores 2.79/1.92 against 2.84/1.89),
with rounds and towns-per-side matching to two decimals throughout. The lesson is the usual
one: when scores are small integers and a fifth of games are draws, win rate is a noisy
statistic and the score means settle an argument faster.

`tests/test_glob.php` and `sim/test_bots.py` pin each rule above to a named test, and
`sim/parity.py` regenerates `tests/fixtures/glob_parity.jsonl` when the bot changes
deliberately — the simulator is the specification, so it is the PHP that moves.

## The rebel bot that works

`MistBot` in `sim/bots.py`, ported to `Bots::mistInsurgencyTurn`, chosen in a solo game by
game option 102. Where `GlobEmpire` plays for **ceiling**, this one plays for
**geography**: every rule in it is a question about where the troops are rather than how
many points are on the table. It takes **every game** off `GlobEmpire` at baseline, where
the heuristic rebels lose 63% of them.

The rules, in the order the turn applies them:

1. **Cash everything already won, richest first.** The rebels placed every card and troops
   are public, so a win is certain *by inspection* — there is no `worst_case` here and
   nothing to gamble on, which is the one real asymmetry between the two bots. Score is
   the garrison overcome. Ties inside a score go to the town touching the most occupied
   towns, because that is the one that will not still be winnable next turn.
2. **A 0-point win counts.** Beating an empty garrison scores nothing and still takes the
   town out of the game, and a town the Empire can never stand in is a town it can never
   draw supply from. The mirror of `GlobEmpire` taking an empty town for the same reason —
   and the reason games against Mist end around round 9 rather than 21.
3. **Take the lead where the Empire cannot answer it.** Spend the fewest cards that clear
   the garrison by exactly one, biggest card first, and prefer the town with the fewest
   troops *adjacent* — a lead only survives if no relief column can walk into it before
   the next resolution. Then repeat, re-deriving the board, while the hand can still
   afford another whole lead.
4. **Never half-commit.** A lead the remaining hand cannot complete is not attempted at
   all: the Empire scores every card in a town it wins, so half a lead is a donation.
5. **Seed empty ground beside a garrison that can spare a troop** (two or more — one troop
   marching out abandons its own town), one card each, biggest adjacent force first.
6. **Spend the bluffs where a pile will be believed**, one at a time, re-reading the board
   between each: empty towns beside troops first, then the town closest to level, where
   one more card changes what the Empire thinks it is looking at. **Never** an empty town
   no troops can reach — the bluff is spent on an audience of nobody — unless the hand has
   nowhere else legal, since the whole hand must go out (Decision 6), in which case the
   nearest town to any troops takes it.

Two things about it worth keeping:

**It uses no randomness at all.** Every tie breaks on the board — adjacency, then town id —
so the bot is a pure function of the position. That is what makes the parity fixture sharp:
a disagreement with the PHP is a real disagreement rather than a different seed, and both
engines can be compared on placements card for card and in order, not just on a win rate.

**Beware what it says about the Empire.** `GlobEmpire` at 64% and at 0% is the same bot;
only the opponent changed. Anything measured against the heuristic rebels — the hand-size
sweep, the starting-garrison sweep, the retreat-margin plateau — is a statement about that
opponent, and worth re-running against Mist before it is trusted. PHP self-play agrees with
the simulator: 300 games at 0.3%/99.0%/0.7% and mean scores 0.26/5.66, against the
simulator's 0%/100% and 0.2/5.8.

`tests/test_mist.php` and `sim/test_bots.py` pin each rule above to a named test, and
`sim/parity.py --bot mist` regenerates `tests/fixtures/mist_parity.jsonl` — 200 random
boards, each with a hand, on which both engines must produce the same resolution and the
same placements exactly.

## Testing the PHP

```bash
php tests/run.php              # all of it
php tests/run.php rules        # only files matching "rules"
php tests/selfplay.php 1000                   # bots against each other, for the win rate
php tests/selfplay.php 1000 heuristic         # the old Empire bot, for comparison
php tests/selfplay.php 1000 glob heuristic    # the old rebel bot, for comparison
```

`tests/selfplay.php` is the check the unit tests cannot give. They prove the PHP does what
it was written to do; self-play asks whether it does what the *specification* does. Run the
same heuristics over the same scenario in both and the distributions should agree:

Different RNGs mean comparing distributions, not games, and 1000 games pins a rate to
roughly ±1.6 points — so agreement at that level is consistent with a faithful port rather
than proof of one. It catches drift, not subtlety. Re-run it after any rules change; it
has agreed to within a couple of points after every one so far, which is the main evidence
that `Rules.php` still matches `engine.py`.

**Two classes of Studio-only bug are now caught locally, both by making the harness
stricter rather than by testing more.** `tests/support/framework.php` pre-creates a decoy
`card` table, because BGA's database already has one and `CREATE TABLE IF NOT EXISTS`
against it is a silent no-op; and its `Globals::inc` throws on a global that was never set,
exactly as BGA does. Each was found by a deploy, and each now fails a test instead. When
the Studio surfaces something the tests missed, tightening the stub is usually the fix.

`tests/support/framework.php` is a small stand-in for the parts of the BGA framework this
game touches, backed by in-memory SQLite. It is not an attempt to reimplement BGA — it
exists so the real `Game`, `Board` and state classes can be run without deploying. It
loads the actual `dbmodel.sql`, rewriting the MySQL-isms, so a schema change that breaks a
query breaks a test rather than a table on the Studio.

`tests/test_rules.php` mirrors `sim/test_engine.py` case for case. `tests/test_game.php`
drives whole games and checks the invariants that matter: every town resolves, the deck is
an exact twelve-turn clock, all 36 presence is accounted for, scoring conserves what was
committed, and no public notification ever carries a hidden card.

**PHP is not installed system-wide.** `~/.local/bin/php` is a standalone static build
(static-php-cli, PHP 8.4.23, single file, no Homebrew). If it goes missing, fetch another
from `https://dl.static-php.dev/static-php-cli/common/`.

## Open questions for a human

1. **The Insurgency can only score where the Empire chooses to stand.** Capture-only
   scoring means its presence is worth nothing in a town the Empire never garrisons — a
   real game ended with 28 presence across four towns scoring zero. The Empire's answer is
   to take empty towns and never contest a stacked one, which is board-shrinking with
   teeth now that resolution also locks supply permanently. **Intrinsic town values** are
   the long-deferred counter and the thing most likely to fix it: if a town is worth points
   to whoever holds it, the Empire cannot ignore a seeded town and the rebels' 28 presence
   buys something. Town supply is a natural place to hang it.
2. **Put the graded cards and troop presence back.** This is now the top of the list
   rather than a someday item. Several sessions of real play have the Empire losing badly,
   and the two subtler levers tried instead — hand size and the starting garrison — are
   both measured and both spent. Troop presence is the one that moves what a garrison is
   *worth*: at presence 1 a lone troop is beaten by two cards, at presence 3 it takes four.
   Graded cards are what make peeking mean anything and what turn a lone-troop attack into
   a bet. See the CRITICAL note on why 0% is expected without them. The last
   roughly-even settings are in the git history at `dedba1a`; expect to re-tune rather than
   restore, since the rules have moved a long way since.
3. **Bots on BGA.** Solo works locally and the framework supports automata
   (`addAutomataPlayerPanel`, `solo_mode_ranked`). Whether BGA permits a bot opponent for a
   game with no published solo variant is unknown and unresearched. Nothing stops it in
   Studio.
4. **Dropping troop movement**, considered and parked 2026-09-03. Kept because the analysis
   was expensive: it is a *swap*, not a deletion — production requires presence and presence
   comes from marching, so the Empire would be welded to its start; production would have to
   become "any town you hold or are adjacent to". The cost is the move-or-look tradeoff.
   The risk is that board-shrinking becomes the Empire's only line. Test in `sim/` first.
5. **Pending resolutions and retreat.** Resolution-first stops you cashing out on arrival,
   but not your opponent punishing an arrival. A declared-but-not-yet-resolved marker would
   also let the Empire *withdraw* a garrison rather than lose it — ceding the town and its
   supply but saving the troops. More state on the board; a genuinely good decision if the
   state is worth it.
6. **Deck order as a mechanic.** Piles are physical — a face-down stack and a face-up area —
   which leaves room for shuffling a town, burying a card, or turning a revealed card back
   down. Nothing designed; the structure is there for it.
7. **Engineers and rebel sabotage.** Production being a town property leaves room for Empire
   units that convert a town into a producer, and Insurgency cards that attack supply or
   production. Noted, not designed.
8. **Side-swap matches.** A full match is arguably two games with the sides traded. Sides
   live in `player.player_side` from game option 100; nothing implements the swap.
9. **The simulator cannot tell you whether a decision is interesting.** Graded cards were
   added on the theory that they make peeking richer, and the measured value of peeking
   *fell* — because the Empire bot collapses every pile into one expected-value number and
   marches at the biggest. Per-card variance actually tripled, so each look genuinely
   carries more. When a change is about decision texture rather than balance, the bots are
   the wrong instrument and the answer has to come from a table.

## Decisions & Constraints

The full set with reasoning is in `ironandwhisper.md` under *Decisions & Constraints*. The
ones a PHP port is most likely to break:

- **Troops are spent at resolution** (Decision 3). See CRITICAL above.
- **Production is a per-town property, and needs the Empire to *hold* the town.**
  `Rules::empireHolds` / `empire_holds` is the test: troops there, or having won it at a
  resolution. A garrison on the spot is not required, so a factory the Empire has taken
  keeps building once the garrison leaves — but an empty town nobody has taken builds for
  nobody. Dropping the check entirely handed the Empire a free second factory it had never
  been near, which is what a real game caught. Supply does not cap production either.
  The rebels *winning* a town stops it for good — which is what makes a production town
  worth taking. The old rule was "one troop
  per turn in total, anywhere the Empire already stands", with a warning that per-town
  generation was degenerate (dilution becomes strictly correct and out-produces the deck).
  That warning lapsed when Decision 3 made dilution costly — a thin garrison loses its
  local fight and is scored — and `ironandwhisper.md` records why. Do not reinstate the
  garrison requirement: it was a chicken-and-egg that left an eliminated Empire playing out
  the clock for nothing. There is no longer a "no troops anywhere" fallback clause; it was
  written in the docs and never existed in either engine.
- **The Insurgency must place its entire hand every turn** (Decision 6). This is what makes
  pile height uninformative and what makes the deck an exact clock. It also makes the game
  length deterministic at `deck_size / hand_size` turns.
- **You may only resolve a town where you have presence** (Decision 5) — Empire needs a
  troop there, Insurgency needs a card in the pile. Without this the Empire freezes empty
  towns from anywhere for free. **The end-of-game sweep obeys the same rule**: a town with
  no troops and no cards is left open rather than handed to whoever wins ties, which
  otherwise filled the log with "the Empire takes it for 0" about towns nobody was ever in.
  `Rules::townIsUncontested` / `town_is_uncontested` is the test, and the end-of-game
  invariant in the tests is now "resolved *or* uncontested", not "resolved".
- **Empire wins ties** (Decision 7).
- **Resolution is a free action, once per turn** (Decision 4), taken *before* generation
  and movement, and judged on the board as the opponent left it.
- **Four ways the game ends, all of which resolve every remaining town at once**
  (Decision 1): the deck runs out, every town is resolved, the Empire is eliminated (no
  troops and no town that will build any), or both sides have a standing **offer to end**
  up. Unresolved towns are deferred, never safe.
- **The offer to end is not a pass.** A pass that skipped a turn would stop the deck
  draining, and the deck is the clock — two cautious players could stall forever, which is
  the exact failure Decision 1 was designed around. It is a standing offer, carried as a
  parameter on the turn action so it cannot be made or withdrawn out of turn, and it lives
  in two globals. Solo needs one offer, not two: the bot has no opinion, so requiring its
  agreement would mean a person could never end a game they had lost interest in.

Constraints the port itself introduced:

- **`Rules.php` stays free of the framework.** No `$this->bga`, no database, no
  notifications. That is what lets `tests/` run at all, and it is the only reason a rules
  bug can be reproduced in a second rather than a deploy cycle.
- **All player-visible filtering goes through `View::forSide`.** One function to get right
  and one function to test. Nothing else may assemble a payload for a client.
- **The bot is not a player row.** `Game::BOT_PLAYER_ID` (0) exists so everything keyed by
  player id keeps working, but the framework creates `player` rows only for real people, so
  the bot's score lives in a global and its name comes from its side. `getAllDatas` puts it
  in a separate `bot` key rather than in `players`: that array is the framework's, and a
  row in it for somebody with no player record invites trouble. The client draws it with
  `playerPanels.addAutomataPlayerPanel` — note BGA's own `.d.ts` deprecation comment points
  at `players.addAutomataPlayerPanel`, which does not exist.
- **The face-down pile is the only secret.** `View::pileView` sends its contents to the
  Insurgency alone; everyone else gets ids with null types. Face-up cards are public by
  definition and go out to all.
- **`applyInsurgencyTurn` and `applyEmpireTurn` still take a `$resolve`, and a person never
  fills it in.** It is the bot's and the simulator's shape — `engine.py` applies a whole
  turn in one call — and it routes through `Game::declareResolution` exactly as the
  `Resolve` state does, so both are held to the same presence check. A human's resolution
  has already happened by the time either is reached. ("Resolve nothing" used to be an
  empty string on the wire, since BGA action parameters have no null; `actSkipResolve` is
  an action of its own and there is no string left to get wrong.)
- **Tests must derive from the scenario, not hard-code its numbers.** Changing `hand_size`
  from 5 to 3 broke seven tests that had 5, 55, 3 and 13 written into them. They now read
  `$game->scenario->handSize` and `->turns()`. A parameter change should cost one line in
  `scenarios/`, not an afternoon.
- **A bot never marches out of the town it resolves.** A person resolves in a phase of
  its own and sees the outcome first, so they may march a garrison out of a town they just
  won. A bot commits its whole turn in one call and cannot know whether those troops still
  exist — if the resolution is lost they are gone before the march and the turn is
  illegal. `sim/bots.py::_hold` and the filter in `Bots::empireTurn` are the same fix; it
  was a live bug from the day resolution moved to the front of the turn, and only surfaced
  when a random-bot game happened to walk into it.
- **Don't change state ids casually.** BGA discovers state classes by scanning
  `modules/php/States/`, so a stale file on the server is a live state class.

Two methodological notes worth keeping:

- **The "Empire premium" heuristic in the design doc does not predict balance.** Equalising
  total force does almost nothing, because capture-only scoring makes troop presence
  self-cancelling. Presence density is the knob that moves the game.
- **Measuring a mechanic inside a broken configuration tells you nothing.** Two findings
  reversed when the rules were fixed — peeking looked inert and board-shrinking looked like
  a trap; both were measured while the Empire won regardless. Re-measure after any rules
  change rather than carrying findings forward.
