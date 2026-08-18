#!/usr/bin/env bash
#
# Bring up a local API the console can actually talk to — one command.
#
# This encodes what TAB 05 had to work out by hand, including three findings that
# each cost a debugging round:
#
#   * PHP is not on PATH on a Herd machine (evidence ledger, TAB 00 toolchain)
#   * the test suite and the image code need memory_limit above the 128M default,
#     and the failure is a *fatal* that the exit status misreports (finding L-02)
#   * the MFA challenge lives in the cache, so an `array` store makes the second
#     factor always report an expired attempt — the challenge issued by one
#     request does not exist for the next
#
# It is deliberately NOT a substitute for `docker compose up`. This runs on
# SQLite, so response *shapes* are real and everything about concurrency, row
# locking and `lockForUpdate` is not — release-gate blocker 4 is untouched by
# anything you prove here. When Docker exists, use the compose file and real
# PostgreSQL.
#
# Usage:
#   tools/local-api.sh up       # migrate, seed, and serve on :8000
#   tools/local-api.sh reset    # throw the database away and start again
#   tools/local-api.sh staff    # give the seeded account a password and MFA
#   tools/local-api.sh down     # stop the server
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

STATE_DIR="${TAYTAY_LOCAL_STATE:-$ROOT/storage/local-api}"
DB="$STATE_DIR/integration.sqlite"
# The environment is named `local`, and that matters more than it looks.
#
# `artisan --env=X` OVERRIDES APP_ENV whatever the env file says, and
# DatabaseSeeder creates the development staff account only when
# `app()->environment('local', 'testing')`. Running with `--env=$ENV_NAME`
# therefore migrated and seeded happily and produced **no account to sign in
# with** — the seeders silently skipped the half that makes the database usable.
ENV_NAME="local"
ENV_FILE="$ROOT/.env.$ENV_NAME"
PORT="${PORT:-8000}"

# ── PHP ───────────────────────────────────────────────────────────────────────
#
# Herd installs PHP outside PATH. Prefer whatever is already on PATH so a normal
# install is not overridden, and fall back to Herd's bin rather than failing with
# "command not found" on a machine that plainly has PHP.
if ! command -v php >/dev/null 2>&1; then
  HERD_BIN="$HOME/Library/Application Support/Herd/bin"
  if [ -x "$HERD_BIN/php" ]; then
    export PATH="$HERD_BIN:$PATH"
  else
    echo "error: no php on PATH, and none at $HERD_BIN" >&2
    exit 1
  fi
fi

# `-d memory_limit` on every invocation: see finding L-02. The image-derivation
# path exhausts the 128M default and dies with a fatal, which the surrounding
# exit status does not reliably report.
php_run() { php -d memory_limit=1G "$@"; }

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

ensure_env() {
  mkdir -p "$STATE_DIR"

  if [ ! -f "$ENV_FILE" ]; then
    if [ ! -f "$ROOT/.env" ]; then
      echo "error: no .env to derive from. Run composer setup first." >&2
      exit 1
    fi

    say "Creating .env.$ENV_NAME (gitignored — it inherits real .env values)"
    sed -e "s|^APP_ENV=.*|APP_ENV=$ENV_NAME|" \
        -e 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|' \
        -e "s|^DB_DATABASE=.*|DB_DATABASE=$DB|" \
        -e 's|^CACHE_STORE=.*|CACHE_STORE=file|' \
        -e 's|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=sync|' \
        -e 's|^SESSION_DRIVER=.*|SESSION_DRIVER=array|' \
        "$ROOT/.env" > "$ENV_FILE"
  fi

  # Re-point the env file at the database this run intends to use, every time.
  #
  # Without this, an .env.integration left over from an earlier run keeps its old
  # DB_DATABASE, the env file wins over the script's own $DB, and `up` reports
  # "Nothing to migrate" while serving a database you did not mean. Silent
  # divergence between two sources of the same fact — the shape of most of the
  # findings in this integration.
  if [ -f "$ENV_FILE" ]; then
    sed -i.bak -e "s|^DB_DATABASE=.*|DB_DATABASE=$DB|" \
               -e 's|^CACHE_STORE=.*|CACHE_STORE=file|' "$ENV_FILE"
    rm -f "$ENV_FILE.bak"
  fi

  # APP_ENV stays `local` on purpose: AccessControlSeeder refuses to run outside
  # local/testing, because a known privileged login on a system holding welfare
  # records is not a convenience.
  touch "$DB"
}

cmd_up() {
  ensure_env

  say "Migrating (SQLite — not a substitute for PostgreSQL)"
  php_run artisan migrate --env=$ENV_NAME --force

  say "Seeding synthetic data"
  php_run artisan db:seed --env=$ENV_NAME --force || true
  php_run artisan db:seed --env=$ENV_NAME --class=DemoDataSeeder --force || true

  say "Serving on http://127.0.0.1:$PORT"
  echo "Health:  curl -s http://127.0.0.1:$PORT/api/v1/health"
  echo "Stop:    tools/local-api.sh down"
  php_run artisan serve --env=$ENV_NAME --host=127.0.0.1 --port="$PORT"
}

cmd_reset() {
  cmd_down || true
  rm -f "$DB"
  say "Database removed. Run 'up' to rebuild it."
}

cmd_down() {
  pkill -f "artisan serve.*--port=$PORT" 2>/dev/null && say "Server stopped." || say "No server running on :$PORT."
}

# Gives the seeded staff account a usable password and a confirmed second factor,
# so the console can complete a real sign-in against this API.
#
# The password is a fixed, obviously-synthetic string. It authenticates nobody
# anywhere else, and it is printed rather than hidden because the whole point is
# to be pasted into a local sign-in form.
cmd_staff() {
  ensure_env
  local password='integration-only-not-a-real-credential'

  # Env vars go before the command, not after: `php -r '...' DB=x` passes DB=x as
  # argv, which PHP ignores and getenv() never sees. Every value comes from the
  # environment and every statement is prepared, so there is not a single quoted
  # literal for the shell to fight over.
  DB="$DB" PW="$password" EMAIL='staff@example.test' php_run -r '
    $pdo = new PDO("sqlite:" . getenv("DB"));
    $find = $pdo->prepare("select uuid from accounts where email = ?");
    $find->execute([getenv("EMAIL")]);
    if ($find->fetchColumn() === false) {
        fwrite(STDERR, "no seeded staff account — run up first" . PHP_EOL);
        exit(1);
    }
    $pdo->prepare("update accounts set password_hash = ? where email = ?")
        ->execute([password_hash(getenv("PW"), PASSWORD_BCRYPT, ["cost" => 12]), getenv("EMAIL")]);
    echo "password set" . PHP_EOL;'


  cat <<EOF

  Sign in with:
    email     staff@example.test
    password  $password

  The account has no second factor, so the API will answer
  'mfa-enrolment-required' with a token that reaches enrolment and nothing else
  (ADR 0043). Enrol one through POST /api/v1/me/mfa, or run the console against
  it and follow the screen.

EOF
}

case "${1:-up}" in
  up) cmd_up ;;
  down) cmd_down ;;
  reset) cmd_reset ;;
  staff) cmd_staff ;;
  *) echo "usage: tools/local-api.sh [up|down|reset|staff]" >&2; exit 1 ;;
esac
