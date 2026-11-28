#!/bin/bash
# pg_dump (custom-format, -Fc) в bind-mount `/backups` (виден на хосте — `storage/backups`,
# см. docker-compose*.yml `postgres.volumes`) + ОБЯЗАТЕЛЬНАЯ проверка restore на одноразовую
# scratch-БД: бэкап без проверенного restore не считается бэкапом.
# Scratch-БД создаётся/дропается в рамках ЭТОГО прогона — не остаётся после команды. Дамп
# на bind-mount ОСТАЁТСЯ (off-host копирование/ротация/cron-расписание — Scope Excluded,
# инфра-решение владельца).
set -euo pipefail

ENV="${1:-dev}"
if [ "$ENV" = "prod" ]; then
  COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env --env-file .env.docker.production)
else
  COMPOSE=(docker compose -f docker-compose.yml --env-file .env)
  [ -f .env.docker ] && COMPOSE+=(--env-file .env.docker)
fi

STAMP="$(date +%Y%m%d_%H%M%S)"
DUMP_FILE="/backups/agelto_${STAMP}.dump"
SCRATCH_DB="agelto_restore_check_${STAMP}"

"${COMPOSE[@]}" exec -T postgres sh -lc '
  set -euo pipefail
  DUMP_FILE="'"${DUMP_FILE}"'"
  SCRATCH_DB="'"${SCRATCH_DB}"'"
  trap '\''psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "DROP DATABASE IF EXISTS \"$SCRATCH_DB\" WITH (FORCE);" >/dev/null 2>&1 || true'\'' EXIT

  echo "db:backup — pg_dump $POSTGRES_DB → $DUMP_FILE"
  pg_dump -Fc --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -f "$DUMP_FILE"

  echo "db:backup — restore-check: создаём $SCRATCH_DB"
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    -c "CREATE DATABASE \"$SCRATCH_DB\" OWNER \"$POSTGRES_USER\";"

  echo "db:backup — restore-check: pg_restore в $SCRATCH_DB"
  pg_restore --username "$POSTGRES_USER" --dbname "$SCRATCH_DB" --no-owner --no-acl "$DUMP_FILE"

  TABLE_COUNT=$(psql -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema = '"'"'public'"'"'" \
    --username "$POSTGRES_USER" --dbname "$SCRATCH_DB")

  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "DROP DATABASE \"$SCRATCH_DB\";"

  if [ "$TABLE_COUNT" -lt 1 ]; then
    echo "db:backup — restore-check FAILED: 0 таблиц восстановлено из $DUMP_FILE" >&2
    exit 1
  fi

  echo "db:backup — restore-check OK: $TABLE_COUNT таблиц восстановлено, scratch-БД удалена, дамп сохранён: $DUMP_FILE"
'
