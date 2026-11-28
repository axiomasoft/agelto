#!/bin/bash
# Идемпотентно создаёт agelto_test в УЖЕ существующем volume (init-скрипты
# /docker-entrypoint-initdb.d выполняются только при первом создании volume'а).
set -euo pipefail

ENV="${1:-dev}"
if [ "$ENV" = "prod" ]; then
  COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env --env-file .env.docker.production)
else
  COMPOSE=(docker compose -f docker-compose.yml --env-file .env)
  [ -f .env.docker ] && COMPOSE+=(--env-file .env.docker)
fi

"${COMPOSE[@]}" exec -T postgres sh -lc '
  psql -tAc "SELECT 1 FROM pg_database WHERE datname = '"'"'agelto_test'"'"'" \
    --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" | grep -q 1 \
  || psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -c "CREATE DATABASE agelto_test OWNER \"$POSTGRES_USER\";"
'
