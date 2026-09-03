# Iron and Whisper — project notes

Asymmetric two-player board game for Board Game Arena. Empire moves visible troops;
Insurgency seeds hidden cards. See `ironandwhisper.md` for the full rules and the
reasoning behind every decision.

---

## CRITICAL

**The rules are settled and encoded in `sim/`. Port from the simulator, not from memory.**
`sim/engine.py` is the executable specification and `sim/test_engine.py` has 33 tests, each
named for the design decision it pins down. If the PHP disagrees with the simulator, the
PHP is wrong. `tests/test_rules.php` mirrors those cases in PHP — when you change a rule,
change it in both places and in `ironandwhisper.md`.

**Do not "simplify" troop consumption.** Troops committed to a town are removed from play
when it resolves. This was tried the other way, measured, and it destroys the game — the
Empire wins 99.7%, and no parameter rescues it. `ironandwhisper.md` Decision 3 records the
numbers; `load_scenario("baseline", consume_troops=False)` reproduces it. It looks like a
harmless simplification. It is not.

**This BGA skeleton is a framework generation newer than zoomquest's.** See the section
below before assuming anything carries over. zoomquest is a useful reference for *shape*
but its framework idioms are obsolete.

**`data/`, `maps/` and `scenarios/` are shared config, read by both the simulator and the
PHP.** Changing their shape means changing both. That sharing is the whole reason the
tuning work transfers.

**Balance numbers are the simulator's, not a playtest's.** `scenarios/baseline.json` now
holds 36 influence : 24 dummy, the balance point the simulator indicated. Nobody has
played it. Re-measure before trusting it, and change it in the JSON — both the simulator
and the PHP read that file, so neither needs code changes to retune.

---

## Current state

The port is written and tested locally, and has not yet run on the Studio.

Done:
- BGA Studio project `ironandwhisper` exists; skeleton downloaded, committed unmodified,
  and successfully round-tripped back up.
- `tools/deploy.sh` works — incremental SFTP sync, now with a client build in front of it.
- Design doc is an implementable draft; all ten decisions resolved with reasoning.
- Full rules simulator, bots, 33 tests, and an exploration notebook.
- **The PHP port**: `dbmodel.sql`, `Scenario`, `Rules`, `Board`, `View`, `Game`, and the
  three game states. See *How the port is put together* below.
- **TypeScript client**: board drawn from the map JSON, staged turns for both sides.
- **49 PHP tests** running locally against SQLite, including whole games end to end.
- `gameinfos.jsonc` is Iron and Whisper, two players. Side assignment is game option 100.

Not done:
- **Nothing has been deployed or played yet.** The first deploy must be
  `./tools/deploy.sh --delete` — see below.
- No stats defined in `stats.jsonc`.
- No game-end statistics or tie-breaker.
- No animations; the client redraws towns rather than moving anything.
- No art: towns are drawn from map coordinates in CSS.

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
scenarios/*.json       references a map, sets the knobs
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
| `modules/php/Scenario.php` | loads `data/`, `maps/`, `scenarios/` | the JSON |
| `modules/php/Board.php` | every read and write of `iaw_town` and `iaw_card` | the database |
| `modules/php/View.php` | what each side may see | Rules' shape |
| `modules/php/Game.php` | setup, sides, scoring, `getAllDatas` | all of the above |
| `modules/php/States/` | turn structure and notifications | all of the above |

`Rules.php` is a direct port of the pure logic in `sim/engine.py`. It returns *plans* —
`planMoves` gives departures and arrivals, `peekPlan` gives look counts, `revealFromPile`
names the cards to turn over — and the state classes persist them. If the two disagree, the PHP is
wrong.

**The state machine** is three states plus the framework's own:

- `InsurgencyTurn` (10) — `actCommitTurn(placements, resolve)`. The whole hand goes out in
  one action, because placement is one simultaneous decision.
- `EmpireTurn` (11) — `actCommitTurn(generateAt, moves, resolve)`. Generation, movement,
  automatic peeking, then the optional resolution, in that order.
- `NextTurn` (90) — upkeep: refill the hand, detect the end, hand over to the other side.
  This is `prepare_turn()`; it runs *before* a player is asked for anything, which is why
  the hand refill and the end of the game both live here.

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
```

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

1. **Board-shrinking.** Freezing towns cheaply is currently the *stronger* Empire line
   (62% vs 46%), because mandatory placement forces the Insurgency to waste influence in
   towns the Empire will not contest. Not dominant, and it costs real troops — but it means
   the Empire's best play involves many zero-point resolutions. The natural counter is
   intrinsic town values, already on the deferred list.
2. **Dropping troop movement**, considered and parked 2026-09-03. The Empire would place
   only, never march, making both sides pure placement games. Notes so the analysis is not
   re-derived: it is a *swap*, not a deletion — generation currently requires presence and
   presence is only obtainable by marching, so the Empire would be welded to its starting
   town; generation would have to become "any town you occupy or are adjacent to". Troop
   consumption must stay, and gets *more* load-bearing: with no movement, resolving a town
   costs your frontier, not just material. The cost is the move-or-look tradeoff — every
   troop would peek every turn, so Empire information rate rises with the army. The risk is
   that board-shrinking (question 1) becomes the Empire's only line rather than its best
   one. Test it in `sim/` behind a scenario flag before any PHP changes; nothing known
   about peeking or shrinking survives the change automatically.
3. **Deck order as a mechanic.** Piles are now physical — a face-down stack and a face-up
   area — which leaves room for shuffling a town, burying a card, or turning a revealed card
   back down. Nothing is designed; the structure is simply there for it.
4. **Side-swap matches.** A full match is arguably two games with the sides traded. The
   groundwork is there — sides live in `player.player_side`, set from game option 100 — but
   nothing implements it.
5. **Playtest the 36:24 numbers.** They come from the simulator and no human has played
   them.

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
