#!/bin/sh
set -eu

cd -- "$(dirname -- "$0")/.."

app_port="${OFICINAFLOW_PORT:-8016}"
export DB_HOST=127.0.0.1
export DB_PORT="${OFICINAFLOW_DB_PORT:-5433}"
export APP_URL="http://localhost:${app_port}"
export SANCTUM_STATEFUL_DOMAINS="localhost:${app_port},127.0.0.1:${app_port}"

docker compose up -d --wait pgsql
php artisan serve --host=127.0.0.1 --port="$app_port"
