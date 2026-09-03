#!/usr/bin/env python3
"""Sync this project up to the BGA Studio SFTP server.

Only uploads files whose size or modification time differs from the copy on the
server, so a routine deploy after editing one file transfers one file.

The SFTP password is read from the macOS Keychain, never from a file. Store it
once with:

    security add-generic-password -a <user> -s bga-studio-sftp -w '<password>' -U

Usage:
    ./tools/deploy.sh                 # sync changed files
    ./tools/deploy.sh --dry-run       # show what would happen, change nothing
    ./tools/deploy.sh --delete        # also remove server files not present locally
    ./tools/deploy.sh --watch         # re-sync every few seconds as you edit
"""

import argparse
import fnmatch
import json
import os
import stat
import subprocess
import sys
import time

import paramiko

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONFIG_PATH = os.path.join(PROJECT_ROOT, "tools", "deploy.json")

# Upload if the local file is at least this many seconds newer than the remote
# one. SFTP timestamps are whole seconds, so a small tolerance avoids re-sending
# files on every run.
MTIME_TOLERANCE = 2


def load_config():
    with open(CONFIG_PATH) as f:
        return json.load(f)


def get_password(cfg):
    """Read the SFTP password out of the macOS Keychain."""
    try:
        return subprocess.check_output(
            ["security", "find-generic-password",
             "-a", cfg["keychain_account"],
             "-s", cfg["keychain_service"], "-w"],
            stderr=subprocess.DEVNULL,
        ).decode().strip()
    except subprocess.CalledProcessError:
        sys.exit(
            f"No Keychain entry for service '{cfg['keychain_service']}' "
            f"account '{cfg['keychain_account']}'. Add one with:\n\n"
            f"  security add-generic-password -a {cfg['keychain_account']} "
            f"-s {cfg['keychain_service']} -w '<password>' -U\n"
        )


def is_excluded(relpath, patterns):
    """True if any path segment, or the whole path, matches an exclude pattern."""
    for pattern in patterns:
        if fnmatch.fnmatch(relpath, pattern):
            return True
        for segment in relpath.split(os.sep):
            if fnmatch.fnmatch(segment, pattern):
                return True
    return False


def local_files(patterns):
    """Map of relative path -> (size, mtime) for everything we intend to deploy."""
    found = {}
    for dirpath, dirnames, filenames in os.walk(PROJECT_ROOT):
        reldir = os.path.relpath(dirpath, PROJECT_ROOT)
        reldir = "" if reldir == "." else reldir

        # Prune excluded directories so we never descend into .git or node_modules.
        dirnames[:] = [
            d for d in dirnames
            if not is_excluded(os.path.join(reldir, d) if reldir else d, patterns)
        ]

        for name in filenames:
            rel = os.path.join(reldir, name) if reldir else name
            if is_excluded(rel, patterns):
                continue
            st = os.stat(os.path.join(dirpath, name))
            found[rel] = (st.st_size, st.st_mtime)
    return found


def remote_files(sftp, remote_dir):
    """Map of relative path -> (size, mtime) for everything already on the server."""
    found = {}

    def walk(remote_path, relative_prefix):
        try:
            entries = sftp.listdir_attr(remote_path)
        except IOError:
            return  # directory does not exist yet
        for entry in entries:
            rel = os.path.join(relative_prefix, entry.filename) if relative_prefix else entry.filename
            if stat.S_ISDIR(entry.st_mode):
                walk(f"{remote_path}/{entry.filename}", rel)
            else:
                found[rel] = (entry.st_size, entry.st_mtime)

    walk(remote_dir, "")
    return found


def ensure_remote_dirs(sftp, remote_dir, relpath, created):
    """Create any missing parent directories for relpath on the server."""
    parts = os.path.dirname(relpath).split(os.sep) if os.path.dirname(relpath) else []
    path = remote_dir
    for part in parts:
        path = f"{path}/{part}"
        if path in created:
            continue
        try:
            sftp.stat(path)
        except IOError:
            sftp.mkdir(path)
        created.add(path)


def sync(sftp, cfg, dry_run, delete):
    patterns = cfg["exclude"]
    remote_dir = cfg["remote_dir"]

    local = local_files(patterns)
    remote = remote_files(sftp, remote_dir)

    to_upload = []
    for rel, (size, mtime) in sorted(local.items()):
        if rel not in remote:
            to_upload.append((rel, "new"))
        else:
            remote_size, remote_mtime = remote[rel]
            if size != remote_size:
                to_upload.append((rel, "changed"))
            elif mtime > remote_mtime + MTIME_TOLERANCE:
                to_upload.append((rel, "changed"))

    to_delete = sorted(set(remote) - set(local)) if delete else []

    created_dirs = set()
    for rel, reason in to_upload:
        print(f"  {'would upload' if dry_run else 'upload'}  {rel}  ({reason})")
        if dry_run:
            continue
        ensure_remote_dirs(sftp, remote_dir, rel, created_dirs)
        local_path = os.path.join(PROJECT_ROOT, rel)
        sftp.put(local_path, f"{remote_dir}/{rel}")
        # Match the remote timestamp to the local one so the next run agrees.
        st = os.stat(local_path)
        sftp.utime(f"{remote_dir}/{rel}", (st.st_atime, st.st_mtime))

    for rel in to_delete:
        print(f"  {'would delete' if dry_run else 'delete'}  {rel}")
        if not dry_run:
            sftp.remove(f"{remote_dir}/{rel}")

    if not to_upload and not to_delete:
        print("  everything up to date")

    return len(to_upload), len(to_delete)


def connect(cfg):
    transport = paramiko.Transport((cfg["host"], cfg["port"]))
    transport.connect(username=cfg["user"], password=get_password(cfg))
    return transport, paramiko.SFTPClient.from_transport(transport)


def main():
    parser = argparse.ArgumentParser(description="Deploy this game to BGA Studio.")
    parser.add_argument("--dry-run", action="store_true",
                        help="show what would be transferred, change nothing")
    parser.add_argument("--delete", action="store_true",
                        help="remove files on the server that no longer exist locally")
    parser.add_argument("--watch", action="store_true",
                        help="keep running, re-syncing whenever local files change")
    parser.add_argument("--interval", type=float, default=2.0,
                        help="seconds between checks in --watch mode (default: 2)")
    args = parser.parse_args()

    cfg = load_config()
    transport, sftp = connect(cfg)
    print(f"connected to {cfg['host']}:{cfg['port']} as {cfg['user']} "
          f"-> /{cfg['remote_dir']}")

    try:
        if not args.watch:
            uploaded, deleted = sync(sftp, cfg, args.dry_run, args.delete)
            print(f"done: {uploaded} uploaded, {deleted} deleted")
            return

        print(f"watching for changes every {args.interval}s — ctrl-c to stop")
        previous = None
        while True:
            current = local_files(cfg["exclude"])
            if current != previous:
                if previous is not None:
                    print(f"\n[{time.strftime('%H:%M:%S')}] change detected")
                sync(sftp, cfg, args.dry_run, args.delete)
                previous = current
            time.sleep(args.interval)
    except KeyboardInterrupt:
        print("\nstopped")
    finally:
        sftp.close()
        transport.close()


if __name__ == "__main__":
    main()
