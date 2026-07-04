#!/bin/sh
set -eu

# Изолированная тестовая БД — только на первом создании volume'а
# (см. skill db-test-preflight/docker-postgres — на существующем volume используйте `make db:create-test`).
if ! psql -tAc "SELECT 1 FROM pg_database WHERE datname = 'agelto_test'" \
    --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" | grep -q 1; then
  psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" \
    -c "CREATE DATABASE agelto_test OWNER \"${POSTGRES_USER}\";"
fi
