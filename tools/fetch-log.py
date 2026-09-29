#!/usr/bin/env python3
"""
Download a Studio game's notification log, for replaying in the simulator.

    python3 tools/fetch-log.py 972791 [972800 ...]

Each table is saved to logs/<table>.json exactly as BGA returns it: the same
endpoint the replay viewer uses, carrying every notification the game sent.
It is what gets hand-copied out of the game log for issue #18 today, as data
rather than as prose.

**The log is one player's view.** It holds what the logged-in account was sent,
so an Empire player's copy has the rebels' cards as ids until they turn over.
Nearly every card does turn over by the end — a look, a resolution or the final
sweep — which is why sim/replay.py can still rebuild the whole game.

**Authentication is your browser session**, kept in the macOS Keychain the way
tools/deploy.py keeps the SFTP password — never in a file:

    security add-generic-password -U -s bga-studio-session -a scotfree -w

and paste the Cookie header from any Studio request in the browser's dev tools.
BGA's AJAX actions also want the session's request token in `X-Request-Token`.
It is read from the table's own page, where BGA embeds it for its scripts,
rather than stored. Sessions expire. When
this one has, BGA answers "Invalid session information" and this script says
to refresh the Keychain entry.

Standard library only, on purpose: it runs with the system python3 and needs
neither venv.
"""

import json
import re
import subprocess
import sys
import urllib.request
from pathlib import Path

HOST = 'https://studio.boardgamearena.com'
KEYCHAIN_SERVICE = 'bga-studio-session'
KEYCHAIN_ACCOUNT = 'scotfree'
TOKEN_COOKIE = 'TournoiEnLigneStudiotkt'

ROOT = Path(__file__).resolve().parent.parent
LOGS = ROOT / 'logs'


def session_cookie():
    result = subprocess.run(
        ['security', 'find-generic-password',
         '-s', KEYCHAIN_SERVICE, '-a', KEYCHAIN_ACCOUNT, '-w'],
        capture_output=True, text=True,
    )
    if result.returncode != 0:
        sys.exit(f'No Keychain entry {KEYCHAIN_SERVICE}/{KEYCHAIN_ACCOUNT}. '
                 'Store the browser Cookie header there first (see the top of this file).')
    return result.stdout.strip()


def request_token(cookie, table):
    """
    The token BGA's AJAX actions expect, as the page itself was given it.

    Every BGA page embeds `requestToken: '...'` in its bootstrap config, and
    that is what the site's own scripts send. Loading the table's page with the
    session cookie and reading it out is what a browser does. The cookie's
    `TournoiEnLigneStudiotkt` value is the fallback, in case a page stops
    carrying it.
    """
    page = get(f'{HOST}/tableview?table={table}', cookie)
    match = re.search(r"requestToken[\"']?\s*:\s*[\"']([^\"']+)[\"']", page)
    if match:
        return match.group(1)

    match = re.search(rf'(?:^|;\s*){TOKEN_COOKIE}=([^;]+)', cookie)
    if not match:
        sys.exit('Found no request token on the table page or in the cookie; '
                 'copy the whole Cookie header again.')
    return match.group(1)


def get(url, cookie):
    request = urllib.request.Request(url, headers={'Cookie': cookie})
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.read().decode('utf-8', errors='replace')


def fetch(table, cookie, token):
    url = f'{HOST}/archive/archive/logs.html?table={table}&translated=true'
    request = urllib.request.Request(url, headers={
        'Cookie': cookie,
        'X-Request-Token': token,
        'X-Requested-With': 'XMLHttpRequest',
        'Referer': f'{HOST}/tableview?table={table}',
    })
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.loads(response.read().decode('utf-8'))


def main(tables):
    if not tables:
        sys.exit(__doc__.strip().splitlines()[2].strip())

    cookie = session_cookie()
    token = request_token(cookie, tables[0])
    LOGS.mkdir(exist_ok=True)

    failed = False
    for table in tables:
        if not table.isdigit():
            print(f'{table}: not a table id')
            failed = True
            continue

        body = fetch(table, cookie, token)
        # BGA reports errors as a 200 with status "0" and a message.
        if str(body.get('status')) != '1':
            error = body.get('error', 'unknown error')
            print(f'{table}: BGA refused: {error}')
            if 'session' in error.lower():
                print('  The session has probably expired: copy a fresh Cookie header into the Keychain.')
            failed = True
            continue

        path = LOGS / f'{table}.json'
        path.write_text(json.dumps(body, indent=1, ensure_ascii=False) + '\n')
        packets = body.get('data', {}).get('logs', [])
        print(f'{table}: {len(packets)} log packets -> {path.relative_to(ROOT)}')

    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main(sys.argv[1:])
