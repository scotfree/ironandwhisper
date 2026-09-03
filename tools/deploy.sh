#!/bin/bash
# Wrapper around deploy.py. Creates the virtualenv on first run, then forwards
# all arguments through.
set -e

TOOLS_DIR="$(cd "$(dirname "$0")" && pwd)"
VENV="$TOOLS_DIR/.venv"

if [ ! -d "$VENV" ]; then
    echo "First run — creating virtualenv in tools/.venv"
    python3 -m venv "$VENV"
    "$VENV/bin/pip" install --quiet --upgrade pip
    "$VENV/bin/pip" install --quiet paramiko
fi

exec "$VENV/bin/python" "$TOOLS_DIR/deploy.py" "$@"
