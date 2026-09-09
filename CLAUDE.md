# Iron and Whispers — project notes

The game is **displayed** as "Iron and Whispers". Every identifier is still
`ironandwhisper` — the repo, the BGA project, the PHP namespace, the remote directory —
and should stay that way. Only `gameinfos.jsonc`'s `game_name` carries the plural.

Asymmetric two-player board game for Board Game Arena. Empire moves visible troops;
Insurgency seeds hidden cards. See `ironandwhisper.md` for the full rules and the
reasoning behind every decision.

---

## CRITICAL

**The rules are settled and encoded in `sim/`. Port from the simulator, not from memory.**
`sim/engine.py` is the executable specification and `sim/test_engine.py` has 45 tests, each
named for the design decision it pins down. If the PHP disagrees with the simulator, the
PHP is wrong. `tests/test_rules.php` mirrors those cases in PHP — when you change a rule,
change it in both places and in `ironandwhisper.md`.

**The loser's commitment is taken; the winner's stays** (Decision 3). The Empire keeps its
garrison in a town it wins, and that garrison keeps carrying supply. This is *not* the old
"troops always survive" arrangement that measured at 99.7% Empire wins — what reopens the
Insurgency's scoring is that Empire troops are still removed when it *loses*, and that
cutting supply starves them without a fight at all. Do not restore the winner-keeps-all
version, and do not make attrition score nothing.

**Supply is a ceiling, not income** (Decision 2). Networks of Empire-occupied towns pool
their towns' supply; that divided by `supply_per_troop` is the most troops the network can
keep standing, and anything over starves at end of turn. Production is a separate per-town
number. The two are independent on purpose — a poor town can be a depot, a rich one can
build nothing. An earlier design had the network contribute *attack strength* instead;
it fails, and `ironandwhisper.md` Decision 2 records why.

**Resolution happens first in a turn, and is judged on the board as your opponent left
it** (Decision 4). It used to be last, which made every resolution risk-free: the Empire
marched a troop in and took the town on arrival, the Insurgency placed exactly enough and
cashed it in the same breath. Do not move it back, and do not let a town staged for
resolution also be a march origin or a placement target — the server resolves first, so the
rest of that turn would be illegal.

**This BGA skeleton is a framework generation newer than zoomquest's.** See the section
below before assuming anything carries over. zoomquest is a useful reference for *shape*
but its framework idioms are obsolete.

**`data/`, `maps/` and `scenarios/` are shared config, read by both the simulator and the
PHP.** Changing their shape means changing both. That sharing is the whole reason the
tuning work transfers.

**`baseline` is currently set for feel, not for balance.** Cards are 0 or 1, a troop is
strength 1 and costs 1 supply, every town supplies 2 — deliberately minimal, at the
player's request, so the shape of the game can be felt. At those numbers the Empire wins
**0%** against the bots. Do not read anything into a game played on them, and do not "fix"
them without asking: the simplification is deliberate. The last roughly-even settings —
graded cards, heterogeneous map, strength 3 — are in the git history at `dedba1a`.

**Why 0% is the ordering change working rather than failing.** With cards worth at most 1,
public pile height is an exact upper bound on a town's influence, and ties go to the Empire,
so N troops beat any N-card pile *with certainty*. The Empire's whole game at these
parameters was the guaranteed snipe: march one troop in, resolve on arrival, take the town
and its supply for free. Resolution-first removes it and leaves the Empire nothing. The
two things that give it a game back are parameter edits, not code: **graded cards** (a
lone-troop attack becomes a bet on whether that card is a 3) and **troop strength above 1**.
Graded cards are also what make concealment mean anything at all — with a maximum of 1,
peeking can only tell you a pile is worth less than you already knew it could not exceed.

**Troop strength against card value is the lever, not the Insurgency's economy.** Cutting
influence from 36 to 18 made the Empire *worse* (2% to 0.3%), because the Empire scores by
capturing influence — a poorer Insurgency is a smaller prize. What moves it is how much a
garrison is worth: at strength 1 a lone troop is beaten by two cards, at strength 3 it
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
- Full rules simulator, bots, 45 tests, an exploration notebook, and a batch runner.
- **The PHP port**: `dbmodel.sql`, `Scenario`, `Rules`, `Bots`, `Board`, `View`, `Game`,
  and the game states. See *How the port is put together* below.
- **TypeScript client**: board from the map JSON, drag-and-drop placement, staged turns,
  supply and network drawn on the board, a log with a line per action.
- **72 PHP tests** against SQLite, plus `tests/selfplay.php` for cross-engine comparison.
- **Heuristic bots** on both sides, and a solo game against one.

Not done, in rough order of how much it hurts:

- **The Empire cannot recover from zero troops.** Production requires a garrison in the
  town, so an Empire wiped out has no way back and the game plays out pointlessly for the
  remaining turns. Agreed fix, not built: let a production town build for the Empire
  whenever the *rebels* have not taken it, garrison or no. It removes the chicken-and-egg
  and the old "no troops anywhere" fallback clause at once. Building would then be allowed
  past the ceiling, since attrition at end of turn settles it — which also lets you build
  and march out to the supply in one motion.
- **No end condition for an eliminated Empire.** Even with the fix above, the game should
  end when the Empire holds no troops and no production town it could rebuild from.
- **Attrition losses are chosen server-side.** The `disband` argument exists and the rules
  honour it, but the client sends an empty one, so losses come off the largest garrisons.
  Choosing badly can sever a second line, so this is a real decision going unmade.
- **The Empire bot does not understand supply when marching.** `empireMoves` marches toward
  attractive piles without checking what abandoning a town does to the ceiling, so it
  routinely walks itself into starvation and donates the points. Discount solo games
  accordingly.
- No stats in `stats.jsonc`, no tie-breaker, no animations, no art.

Unverified, and worth checking first thing on the Studio: **BGA caches game metadata
separately from the files.** `gameinfos.jsonc` on the server is correct — one or two
players, named "Iron and Whispers" — but neither the solo option nor the new name appeared
in the lobby. The likely cause is that game information needs reloading from the Studio
control panel. Nobody has confirmed it.

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
data/units.json        strength / movement / peek per unit type
data/cards.json        influence value per card type
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
sim/.venv/bin/python -m pytest sim -q            # 33 tests
sim/.venv/bin/python -m sim.run --games 500      # batch runner
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

**The state machine** is three states plus the framework's own:

- `InsurgencyTurn` (10) — `actCommitTurn(placements, resolve)`. The optional resolution
  first, then the whole hand in one action, because placement is one simultaneous decision.
- `EmpireTurn` (11) — `actCommitTurn(produce, moves, resolve, disband)`. The optional
  resolution first, then building, marching, automatic looking, and attrition last.
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

## Testing the PHP

```bash
php tests/run.php              # all of it
php tests/run.php rules        # only files matching "rules"
php tests/selfplay.php 1000    # bots against each other, for the win rate
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
an exact twelve-turn clock, all 36 influence is accounted for, scoring conserves what was
committed, and no public notification ever carries a hidden card.

**PHP is not installed system-wide.** `~/.local/bin/php` is a standalone static build
(static-php-cli, PHP 8.4.23, single file, no Homebrew). If it goes missing, fetch another
from `https://dl.static-php.dev/static-php-cli/common/`.

## Open questions for a human

1. **The Insurgency can only score where the Empire chooses to stand.** Capture-only
   scoring means its influence is worth nothing in a town the Empire never garrisons — a
   real game ended with 28 influence across four towns scoring zero. The Empire's answer is
   to take empty towns and never contest a stacked one, which is board-shrinking with
   teeth now that resolution also locks supply permanently. **Intrinsic town values** are
   the long-deferred counter and the thing most likely to fix it: if a town is worth points
   to whoever holds it, the Empire cannot ignore a seeded town and the rebels' 28 influence
   buys something. Town supply is a natural place to hang it.
2. **Put the graded cards and troop strength back**, when the minimal version has served
   its purpose. See the CRITICAL note on why 0% is expected without them.
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
- **Generation is one troop per turn in total**, not per town, placed in any town the
  Empire already occupies. The per-town reading is degenerate — dilution becomes strictly
  correct and out-produces the whole Insurgency deck. Resolved towns do **not** anchor
  generation. One fallback: with no troops anywhere, the Empire may raise its next troop in
  any unresolved town.
- **The Insurgency must place its entire hand every turn** (Decision 6). This is what makes
  pile height uninformative and what makes the deck an exact clock. It also makes the game
  length deterministic at `deck_size / hand_size` turns.
- **You may only resolve a town where you have presence** (Decision 5) — Empire needs a
  troop there, Insurgency needs a card in the pile. Without this the Empire freezes empty
  towns from anywhere for free.
- **Empire wins ties** (Decision 7).
- **Resolution is a free action, once per turn** (Decision 4), taken after generation and
  movement — so judge resolutions against where troops *will be*, not where they are.
- **Deck exhaustion ends the game and resolves every remaining town at once** (Decision 1).
  Unresolved towns are deferred, never safe.

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
- **The client sends an empty string where it means null.** BGA action parameters travel as
  strings, so both turn actions normalise `''` to `null` before anything else happens.
  Without that, "resolve nothing" looks like a request to resolve a town named `""`.
- **Don't change state ids casually.** BGA discovers state classes by scanning
  `modules/php/States/`, so a stale file on the server is a live state class.

Two methodological notes worth keeping:

- **The "Empire premium" heuristic in the design doc does not predict balance.** Equalising
  total force does almost nothing, because capture-only scoring makes troop strength
  self-cancelling. Influence density is the knob that moves the game.
- **Measuring a mechanic inside a broken configuration tells you nothing.** Two findings
  reversed when the rules were fixed — peeking looked inert and board-shrinking looked like
  a trap; both were measured while the Empire won regardless. Re-measure after any rules
  change rather than carrying findings forward.
