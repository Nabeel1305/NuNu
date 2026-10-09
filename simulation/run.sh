#!/usr/bin/env bash
# End-to-end simulation: boots the real HTTP server, queue workers and a fake
# partner webhook server, seeds demo data, then plays partners and callers against it.
#
#   ./simulation/run.sh                 SQLite, one web worker (no database server needed)
#   SIM_DB=mysql ./simulation/run.sh    MySQL/MariaDB from .env, 8 web workers, 2 queue workers,
#                                       plus the concurrency scenarios. Uses the database named by
#                                       SIM_MYSQL_DATABASE (default offline_platform_sim); it is
#                                       dropped and recreated, so the name must end in _sim.
#
# Add SIM_REDIS=1 to use Redis for the queue, cache and rate limits (the recommended production
# setup) instead of the database. Uses SIM_REDIS_HOST (127.0.0.1) and SIM_REDIS_PORT (6379) and
# a unique key prefix per run, so it never flushes or collides with other data in that Redis.
#
# Set PHP_BIN if PHP needs extra flags (e.g. to load pdo_sqlite).
set -euo pipefail
cd "$(dirname "$0")/.."

export PHP_BIN="${PHP_BIN:-php}"
export APP_ENV=local
export PLATFORM_ALLOW_PRIVATE_WEBHOOK_URLS=true PLATFORM_WEBHOOK_BACKOFF=1,2,3
# The simulation hammers one tenant; real limits are covered by the tests.
export PLATFORM_REDEEM_TENANT_PER_MINUTE=100000 PLATFORM_API_PER_MINUTE=100000
export SIM_CREDS="$PWD/storage/app/simulation.json" SIM_EVENTS="$PWD/storage/app/simulation-events.jsonl"

if [ "${SIM_DB:-sqlite}" = "mysql" ]; then
  export DB_CONNECTION=mysql DB_DATABASE="${SIM_MYSQL_DATABASE:-offline_platform_sim}"
  export CACHE_STORE=database SESSION_DRIVER=file QUEUE_CONNECTION=database
  export PHP_CLI_SERVER_WORKERS="${SIM_WORKERS:-8}" QUEUE_WORKERS="${SIM_QUEUE_WORKERS:-2}"
  $PHP_BIN simulation/mysql-reset.php
else
  export DB_CONNECTION=sqlite DB_DATABASE="$PWD/storage/simulation.sqlite"
  export DB_BUSY_TIMEOUT=10000 DB_JOURNAL_MODE=WAL DB_SYNCHRONOUS=NORMAL
  export CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database
  export DB_QUEUE_CONNECTION=queue_sqlite DB_QUEUE_DATABASE="$PWD/storage/simulation-queue.sqlite"
  export QUEUE_WORKERS=1
  rm -f "$DB_DATABASE" "$DB_DATABASE-wal" "$DB_DATABASE-shm" "$DB_QUEUE_DATABASE" "$DB_QUEUE_DATABASE-wal" "$DB_QUEUE_DATABASE-shm"
  touch "$DB_DATABASE" "$DB_QUEUE_DATABASE"
fi

if [ "${SIM_REDIS:-0}" = "1" ]; then
  export CACHE_STORE=redis QUEUE_CONNECTION=redis REDIS_CLIENT="${REDIS_CLIENT:-predis}"
  export REDIS_HOST="${SIM_REDIS_HOST:-127.0.0.1}" REDIS_PORT="${SIM_REDIS_PORT:-6379}"
  export REDIS_PREFIX="sim_$(date +%s)_$$_" CACHE_PREFIX="sim_$(date +%s)_$$_"
  unset DB_QUEUE_CONNECTION DB_QUEUE_DATABASE
fi

rm -f "$SIM_EVENTS" storage/app/simulation-seen.json
touch "$SIM_EVENTS"
rm -rf storage/framework/cache/data/*

$PHP_BIN artisan migrate --force --quiet
[ "${SIM_DB:-sqlite}" = "mysql" ] || [ "${SIM_REDIS:-0}" = "1" ] || $PHP_BIN artisan migrate --database=queue_sqlite --force --quiet
$PHP_BIN artisan db:seed --force --quiet

pids=()
cleanup() { for p in "${pids[@]}"; do kill "$p" 2>/dev/null || true; done; }
trap cleanup EXIT

$PHP_BIN -S 127.0.0.1:9101 simulation/receiver.php >storage/logs/simulation-receiver.log 2>&1 & pids+=($!)
$PHP_BIN -S 127.0.0.1:8099 -t public public/index.php >storage/logs/simulation-app.log 2>&1 & pids+=($!)
for i in $(seq 1 "$QUEUE_WORKERS"); do
  $PHP_BIN artisan queue:work --sleep=1 --tries=4 >"storage/logs/simulation-worker-$i.log" 2>&1 & pids+=($!)
done

for _ in $(seq 1 50); do
  curl -sf http://127.0.0.1:8099/up >/dev/null 2>&1 && break
  sleep 0.2
done

$PHP_BIN simulation/run.php
