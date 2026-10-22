# Handoff: ironandwhisper-20261022-1518-private-alpha

- Repo / branch / HEAD: ironandwhisper / `main` / the `wrapup: handoff` commit after `9b0cb88` (pushed). `gpt-empire` branch at `33055e4` is **local only, never pushed**.
- Session: 2026-10-11 → 2026-10-22, the run-up to BGA private alpha, plus MonoLoc and an Empire GPT.

## Where things stand
- **Private alpha requested 2026-10-22.** The confirmation email asked for nothing; we're waiting on BGA. The checklist is done and recorded in `design.md` › Path to Release.
- Studio is deployed and in sync with `main` (a deploy dry run shows nothing to upload).
- Built this session, all on `main`:
  - MonoLoc, the Empire bot (option 101 value 4).
  - Zombies now play MonoLoc and Mist2 (BGA zombie level 2).
  - Shared JSON compiled into `modules/php/Material.php`.
  - Font bundled in `fonts/`.
  - Swipe layout on narrow screens.
  - Real-time extra time tripled, plus a top-up after resolving.
  - Side-colour band on the Game Status frame.
  - No resolution card during replays.
- Manual published at https://scotfree.github.io/ironandwhisper/rules.html (GitHub Pages from `main`'s root). The wiki version is drafted in `misc/gamehelp.wiki`.
- Logs saved this session: 984120, 985910, 984830, 986540. The last two are written up on #18.
- Empire GPT on the `gpt-empire` branch: `notebooks/gpt_empire.ipynb` plus `gpt/empire.py`. The clone agrees with MonoLoc on about 80% of choices and wins 30% against Mist2, where MonoLoc wins 41%. Short policy-gradient runs haven't improved on that.

## Next step
Wait for BGA's alpha email. If nothing arrives by about **2026-11-05**, ping Studio support. When it comes, follow its instructions and invite players.

## Open questions for Scot
1. **Wiki page:** how is `Gamehelpironandwhisper` created? Scot is finding out. The content is ready in `misc/gamehelp.wiki`.
2. **Wrong help link:** a mistyped rules link on the BGA game page can't be deleted from the game page itself. Check the Metadata Manager's links field, or ask Studio support.
3. **Project name:** BGA expects `ironandwhispers`. We ignored the warning and added a note to the request. It's BGA's call whether a migration is needed before public release.
4. **GitHub issues:** close #15 (pre-alpha checklist, done)? Comment on #17 (mobile now renders)? Not done; Scot didn't say.
5. **GPT next steps:** longer cloning, a wider model, or a real policy-gradient script with workers. Not filed as an issue; parked.

## Decisions this session
- Display name stays "Iron and Whispers" with singular identifiers; the alpha warning about it was ignored (see the open question). A rename was offered as an option, not done.
- No new mechanics. MonoLoc is a *new* bot; the Monolith is unchanged.
- Zombies use the strongest bots (option b) rather than declaring level 0.
- Mobile: map and side column as swipe panels; `game_interface_width.min` 520.
- Real-time extra time is **3x the skeleton** (90/120/150 s). Scot said "3x" and I read it against the skeleton, not my 45/60/90 proposal.
- Side indicator: Scot rejected a rules-summary frame at the top ("real estate too valuable") in favour of a coloured title band on Game Status.
- Art and generators live in `misc/art/`; never deploy them.
- BGG entry: not needed for private alpha.

## Issues filed / relevant
- None filed (Scot: "no real issues to file").
- Relevant: #1 (location value), #17 (mobile), #18 (bot logs and write-ups), #15 (checklist).

## Gotchas
- `tools/deploy.sh` syncs the **working tree**, untracked files included. Run `git status` first. Never `--delete` blindly: the server holds BGA's own files.
- Studio tables become unfetchable within a day. Fetch logs the same day. If the Keychain cookie needs refreshing: `security add-generic-password -U -s bga-studio-session -a scotfree -w "$(pbpaste)"`.
- The Studio checker greps the text, comments included (`player_score`), and refuses `file_get_contents` and `@import`. `tests/test_bga_checks.php` covers these.
- Minified testing leaves `.original` copies on the server that go stale, and the checker reads them.
- `main`'s `.gitignore` hides `gpt/`; the `gpt-empire` branch lifts it. jupytext runs from lcgpt's venv binary.

## Verify state on pickup
```
git log --oneline -3 && git status -sb
./tools/deploy.sh --dry-run          # expect: everything up to date
~/.local/bin/php tests/run.php | tail -1      # 172 passed
sim/.venv/bin/python -m pytest sim -q | tail -1   # 185 passed, 4 skipped
curl -s -o /dev/null -w "%{http_code}\n" https://scotfree.github.io/ironandwhisper/rules.html   # 200
```
