#!/usr/bin/env bash
#
# RUN THE SUITE AGAINST THE DATABASE PRODUCTION ACTUALLY USES.
#
# The tests run on SQLite, production runs on PostgreSQL, and the gap between them has now
# produced six defects that a fully green SQLite suite could not see -- among them an export
# feature that 500ed in production, a lost idempotency race on the money-write path, and a role
# that was not in force at the instant it was granted. Every one was found by running this once.
#
# Once is the problem this script exists to solve. It provisions a throwaway cluster, runs the
# suite against it, and removes it again, so the run needs no standing server and leaves nothing
# behind.
#
# Usage:
#   scripts/postgres-suite.sh                 provision a throwaway cluster, run, tear down
#   scripts/postgres-suite.sh --keep          leave the cluster running afterwards, and say where
#   DB_HOST=... DB_PORT=... scripts/postgres-suite.sh --existing   use a server already running
#
# Anything after `--` is passed to `php artisan test`, so a single test can be run the same way:
#   scripts/postgres-suite.sh -- --filter=RoleValidityWindowTest
#
set -euo pipefail

KEEP=0
USE_EXISTING=0
ARTISAN_ARGS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --keep) KEEP=1; shift ;;
        --existing) USE_EXISTING=1; shift ;;
        --) shift; ARTISAN_ARGS=("$@"); break ;;
        *) echo "unknown option: $1" >&2; exit 64 ;;
    esac
done

cd "$(dirname "$0")/.."

DB_NAME="${DB_DATABASE:-lguids_pg_suite}"

# ── Finding the binaries ────────────────────────────────────────────────────────────
#
# NOT ON PATH IS NOT THE SAME AS NOT INSTALLED, and the difference cost this project a fortnight.
# ADR 0047 recorded the PostgreSQL gap as blocked on a missing Docker runtime while Postgres.app
# sat installed on the same machine, never started, its binaries simply not on PATH. So look in
# the places a real install puts them before concluding anything.
find_bindir() {
    # An explicit override wins, and a WRONG one fails rather than falling back to a search --
    # otherwise a typo here would silently test against some other installation.
    if [[ -n "${PG_BINDIR:-}" ]]; then
        [[ -x "${PG_BINDIR}/pg_ctl" ]] || return 1
        echo "${PG_BINDIR}"
        return 0
    fi

    if command -v pg_ctl >/dev/null 2>&1; then
        dirname "$(command -v pg_ctl)"
        return 0
    fi

    local candidate
    for candidate in \
        /Applications/Postgres.app/Contents/Versions/latest/bin \
        /opt/homebrew/opt/postgresql@18/bin \
        /opt/homebrew/opt/postgresql@17/bin \
        /opt/homebrew/bin \
        /usr/local/opt/postgresql/bin \
        /usr/lib/postgresql/18/bin \
        /usr/lib/postgresql/17/bin
    do
        if [[ -x "${candidate}/pg_ctl" ]]; then
            echo "${candidate}"
            return 0
        fi
    done

    return 1
}

run_suite() {
    echo "── running the suite on PostgreSQL at ${DB_HOST_USED}:${DB_PORT_USED}/${DB_NAME}"
    DB_CONNECTION=pgsql \
    DB_HOST="${DB_HOST_USED}" \
    DB_PORT="${DB_PORT_USED}" \
    DB_DATABASE="${DB_NAME}" \
    DB_USERNAME="${DB_USER_USED}" \
    DB_PASSWORD="${DB_PASSWORD:-}" \
        php artisan test "${ARTISAN_ARGS[@]+"${ARTISAN_ARGS[@]}"}"
}

if [[ "${USE_EXISTING}" -eq 1 ]]; then
    DB_HOST_USED="${DB_HOST:-127.0.0.1}"
    DB_PORT_USED="${DB_PORT:-5432}"
    DB_USER_USED="${DB_USERNAME:-postgres}"
    run_suite
    exit $?
fi

BINDIR="$(find_bindir)" || {
    cat >&2 <<'MSG'
No PostgreSQL binaries found.

This gate cannot be satisfied without them, and it deliberately FAILS rather than passing quietly:
a green result here is a claim that the code was exercised against the engine production uses, and
skipping would make that claim falsely.

Install PostgreSQL (Postgres.app, or `brew install postgresql@18`), or point this at a server you
already have:

    DB_HOST=... DB_PORT=... scripts/postgres-suite.sh --existing
MSG
    exit 69
}

echo "── postgres binaries: ${BINDIR}"

# ── A socket directory with a SHORT path ────────────────────────────────────────────
#
# A Unix socket path has a hard limit near 103 bytes, and the server fails to start with a message
# that does not mention it. A data directory nested under a long scratchpad path is enough to blow
# the limit, so the socket lives directly under /tmp regardless of where the data does.
SOCKET_DIR="$(mktemp -d /tmp/pgs.XXXXXX)"
DATA_DIR="$(mktemp -d /tmp/pgdata.XXXXXX)"

cleanup() {
    if [[ "${KEEP}" -eq 1 ]]; then
        echo "── kept: port ${DB_PORT_USED}, data ${DATA_DIR}, socket ${SOCKET_DIR}"
        echo "   stop it with: ${BINDIR}/pg_ctl -D ${DATA_DIR} stop"
        return
    fi

    "${BINDIR}/pg_ctl" -D "${DATA_DIR}" -m immediate stop >/dev/null 2>&1 || true
    rm -rf "${DATA_DIR}" "${SOCKET_DIR}"
}
trap cleanup EXIT

# ── A port nobody else is on ────────────────────────────────────────────────────────
#
# CHOSEN BY PROBING, NEVER FIXED. A fixed port once connected this suite to ANOTHER agent's PGlite
# instance, which answered `select version()` convincingly enough that the mistake took a while to
# notice. A port that is already open belongs to somebody, and it is not this script's.
DB_PORT_USED=""
for candidate in $(seq 55440 55480); do
    if ! nc -z 127.0.0.1 "${candidate}" >/dev/null 2>&1; then
        DB_PORT_USED="${candidate}"
        break
    fi
done

if [[ -z "${DB_PORT_USED}" ]]; then
    echo "no free port in 55440-55480" >&2
    exit 69
fi

DB_HOST_USED="127.0.0.1"
DB_USER_USED="$(whoami)"

echo "── initialising a throwaway cluster (port ${DB_PORT_USED})"
"${BINDIR}/initdb" -D "${DATA_DIR}" -U "${DB_USER_USED}" --auth=trust >/dev/null

# fsync off: this cluster is destroyed at the end of the run, so durability buys nothing and costs
# a great deal of wall clock on a suite that migrates repeatedly.
"${BINDIR}/pg_ctl" -D "${DATA_DIR}" -o "-k ${SOCKET_DIR} -p ${DB_PORT_USED} -c listen_addresses=127.0.0.1 -c fsync=off" -w start >/dev/null

"${BINDIR}/createdb" -h 127.0.0.1 -p "${DB_PORT_USED}" -U "${DB_USER_USED}" "${DB_NAME}"

run_suite
