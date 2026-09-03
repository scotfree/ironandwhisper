# Iron and Whisper — project notes

Asymmetric two-player board game for Board Game Arena. Empire moves visible troops;
Insurgency seeds hidden cards. See `ironandwhisper.md` for the full rules and the
reasoning behind every decision.

---

## CRITICAL

**The rules are settled and encoded in `sim/`. Port from the simulator, not from memory.**
`sim/engine.py` is the executable specification and `sim/test_engine.py` has 33 tests, each
named for the design decision it pins down. If the PHP disagrees with the simulator, the
PHP is wrong.

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

**Balance numbers are not settled.** The simulator says balance sits near 36 influence : 24
dummy, but `scenarios/baseline.json` still holds the original 30:30. Picking the real
numbers is a design decision, deliberately left to a human.

---

## Current state

Done:
- BGA Studio project `ironandwhisper` exists; skeleton downloaded, committed unmodified,
  and successfully round-tripped back up.
- `tools/deploy.sh` works — incremental SFTP sync, verified.
- Design doc is an implementable draft; all ten decisions resolved with reasoning.
- Full rules simulator, bots, 33 tests, and an exploration notebook.

Not done:
- **No game code written yet.** The skeleton is still BGA's boilerplate.
- `gameinfos.jsonc` still says `"game_name": "My Great Game"` and `"players": [2, 3, 4]`.
  Must become Iron and Whisper, 2 players only.
- `dbmodel.sql` is still all commented-out examples.
- TypeScript not activated — `src-disabled/` has not been renamed.
- Which BGA player is the Empire and which the Insurgency is undecided.

---

## Deploying

```bash
./tools/deploy.sh              # sync changed files
./tools/deploy.sh --dry-run    # show what would transfer
./tools/deploy.sh --watch      # re-sync on save, for the edit/reload loop
./tools/deploy.sh --delete     # prune server files that no longer exist locally
```

- Host `1.studio.boardgamearena.com`, port **2022**, user `scotfree`, remote dir
  `/ironandwhisper`.
- **Password lives in the macOS Keychain**, service `bga-studio-sftp`, account `scotfree`.
  Never in a file. `tools/deploy.py` reads it via `security find-generic-password`.
- Config and exclude list: `tools/deploy.json`. Only the game files and the shared JSON
  reach BGA — `sim/`, `notebooks/`, `tools/`, `misc/`, `src*/` and `*.md` are excluded.
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
  **return the next state's class** (`return NextPlayer::class;`).
- `setupNewGame()` returns the starting state class.
- Services are injected: `$this->bga->notify`, `->playerScore`, `->counterFactory`,
  `->debug`.
- `bga-framework.d.ts` (58KB) ships in the repo — real type definitions for the client
  API, which is otherwise thinly documented. The main practical argument for TypeScript.
- `src-disabled/` → rename to `src/`, then `npm install && npm run build` compiles
  TS → `modules/js/Game.js` and SCSS → `ironandwhisper.css`. **If TS is activated, the
  build must run before deploy** or stale compiled output ships.
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
notebooks/             exploration.ipynb + build_notebook.py that generates it
tools/                 deploy script and config
modules/php/           BGA game logic (still boilerplate)
modules/js/            compiled client (still boilerplate)
```

Map and scenario are split so the same board can run at many parameter settings without
duplicating the graph.

---

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

## What the PHP port still has to design

**`dbmodel.sql`** — the state model. From `sim/engine.py`:
- Per town: `troops` (int), `resolved`, `winner`, `resolved_influence`, `resolved_strength`.
  Town ids and adjacency come from the map JSON, not the database.
- Per card: type, and **its position in the pile**. Pile order is load-bearing — index 0 is
  the top, new cards are placed on top, and peeking draws from the top and returns to the
  bottom. BGA's `Deck` component may not model an ordered rotating pile cleanly; check
  before committing to it.
- Which cards the Empire has peeked at. In the simulator this is a set of card uids.

**Hidden information is the thing most likely to go wrong.** `getAllDatas(int $currentPlayerId)`
must filter per player, and the asymmetry is unusual:
- The **Insurgency** placed every card, so it legitimately sees every pile in full.
- The **Empire** may see only pile *heights*, plus cards it has peeked at, plus resolved
  piles (which are face up to everyone).

Peek results must go out via a private notification to the Empire, never `notify->all`.
`sim/bots.py::EmpireBelief` is the reference for exactly what the Empire is entitled to
know — it was written to read only Empire-legal information.

**Automatic peeking.** In the rules, every troop that did not move peeks; there is no
decision in it, so the simulator applies it automatically rather than making it an action.
The PHP should do the same — it is server-side computation at end of the move step, not a
player prompt.

---

## Open questions for a human

1. **Final parameters.** Simulator says ~36:24; `scenarios/baseline.json` still has 30:30.
2. **Side assignment.** How does a BGA player become the Empire or the Insurgency — game
   option, random, or seat order?
3. **Board-shrinking.** Freezing towns cheaply is currently the *stronger* Empire line
   (62% vs 46%), because mandatory placement forces the Insurgency to waste influence in
   towns the Empire will not contest. Not dominant, and it costs real troops — but it means
   the Empire's best play involves many zero-point resolutions. The natural counter is
   intrinsic town values, already on the deferred list.
4. **TypeScript.** Agreed in principle, not yet activated.

---

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

Two methodological notes worth keeping:

- **The "Empire premium" heuristic in the design doc does not predict balance.** Equalising
  total force does almost nothing, because capture-only scoring makes troop strength
  self-cancelling. Influence density is the knob that moves the game.
- **Measuring a mechanic inside a broken configuration tells you nothing.** Two findings
  reversed when the rules were fixed — peeking looked inert and board-shrinking looked like
  a trap; both were measured while the Empire won regardless. Re-measure after any rules
  change rather than carrying findings forward.
