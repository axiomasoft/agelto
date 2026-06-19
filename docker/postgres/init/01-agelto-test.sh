#!/bin/sh
set -eu

# Изолированная тестовая БД с PostGIS (паритет с основной БД — тесты ловят PG-специфику).
if ! psql -tAc "SELECT 1 FROM pg_database WHERE datname = 'agelto_test'" \
    --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" | grep -q 1; then
  psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" \
    -c "CREATE DATABASE agelto_test OWNER \"${POSTGRES_USER}\";"
fi

psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "agelto_test" \
  -c "CREATE EXTENSION IF NOT EXISTS postgis;"
