#!/bin/bash
# Wrapper around deploy.py. Creates the virtualenv on first run, builds the
# TypeScript/SCSS client, then forwards all arguments through.
set -e

TOOLS_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$TOOLS_DIR/.." && pwd)"
VENV="$TOOLS_DIR/.venv"

# The client is compiled: src/ is excluded from the upload and modules/js/Game.js
# plus ironandwhisper.css are what actually ship. Building here is what stops
# stale compiled output reaching the Studio.
BUILD=1
ARGS=()
for arg in "$@"; do
    case "$arg" in
        --no-build)  BUILD=0 ;;
        --dry-run)   BUILD=0; ARGS+=("$arg") ;;
        --watch)     BUILD=0; ARGS+=("$arg")
                     echo "Watch mode: run 'npm run watch' in another terminal to recompile the client." ;;
        *)           ARGS+=("$arg") ;;
    esac
done

if [ "$BUILD" = "1" ]; then
    if [ ! -d "$PROJECT_DIR/node_modules" ]; then
        echo "First run — installing node dependencies"
        (cd "$PROJECT_DIR" && npm install)
    fi
    (cd "$PROJECT_DIR" && npm run build)
fi

if [ ! -d "$VENV" ]; then
    echo "First run — creating virtualenv in tools/.venv"
    python3 -m venv "$VENV"
    "$VENV/bin/pip" install --quiet --upgrade pip
    "$VENV/bin/pip" install --quiet paramiko
fi

exec "$VENV/bin/python" "$TOOLS_DIR/deploy.py" "${ARGS[@]}"
