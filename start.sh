#!/usr/bin/env bash
# ATS Checker — start local dev server

set -e

DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

PORT="${PORT:-8000}"
HOST="127.0.0.1"
PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "PHP CLI executable not found: $PHP_BIN" >&2
    exit 1
fi

if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 70400 ? 0 : 1);'; then
    echo "ATS Checker requires PHP 7.4 or newer." >&2
    exit 1
fi

# Update APP_URL in .env if needed
if grep -q '^APP_URL=' .env; then
    sed -i "s|^APP_URL=.*|APP_URL=http://$HOST:$PORT|" .env
else
    echo "APP_URL=http://$HOST:$PORT" >> .env
fi

echo "ATS Checker — http://$HOST:$PORT"
"$PHP_BIN" -S "$HOST:$PORT" -t "$DIR" >/dev/null 2>&1 &
SERVER_PID=$!

echo "Server started (PID $SERVER_PID)"
echo "Press Ctrl+C to stop"

trap "kill $SERVER_PID 2>/dev/null; echo 'Stopped.'" EXIT
wait $SERVER_PID
