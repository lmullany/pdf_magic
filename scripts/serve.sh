#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
BIND_HOST="${1:-localhost}"
PORT="${2:-8000}"
PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "PHP executable '$PHP_BIN' was not found on PATH. Set PHP_BIN to its location." >&2
    exit 1
fi

if [[ ! "$PORT" =~ ^[0-9]+$ ]]; then
    echo "Invalid port '$PORT'; expected a number from 1 to 65535." >&2
    exit 1
fi
PORT=$((10#$PORT))
if (( PORT < 1 || PORT > 65535 )); then
    echo "Invalid port '$PORT'; expected a number from 1 to 65535." >&2
    exit 1
fi

echo "Starting PDF Magic in $APP_ROOT"
echo "URL: http://$BIND_HOST:$PORT/"
echo 'Press Ctrl+C to stop.'

cd "$APP_ROOT"
exec "$PHP_BIN" -S "$BIND_HOST:$PORT"
