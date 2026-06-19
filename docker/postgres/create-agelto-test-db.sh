#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT}"

mode="${1:-dev}"
if [[ "${mode}" == "prod" ]]; then
  COMPOSE=(docker compose -f docker-compose.prod.yml)
else
  COMPOSE=(docker compose)
fi

"${COMPOSE[@]}" exec -T pgsql sh -lc '
set -eu
if ! psql -U "$POSTGRES_USER" -d postgres -Atq -c "SELECT 1 FROM pg_database WHERE datname = '\''agelto_test'\''" | grep -qx 1; then
  psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 \
    -c "CREATE DATABASE agelto_test OWNER \"${POSTGRES_USER}\";"
  echo "Created database agelto_test."
fi
# PostGIS-паритет с основной БД (нужен для гео-миграций при RefreshDatabase)
psql -U "$POSTGRES_USER" -d agelto_test -v ON_ERROR_STOP=1 \
  -c "CREATE EXTENSION IF NOT EXISTS postgis;"
echo "agelto_test ready (postgis enabled)."
'
