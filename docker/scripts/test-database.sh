#!/usr/bin/env bash

set -euo pipefail

root_dir=''
env_file=''
project_name=''
create=false

usage() { echo 'usage: test-database.sh --root <repository> --env-file <path> --project-name <name> [--create]' >&2; }

while (($#)); do
    case "$1" in
        --root) root_dir="$2"; shift 2 ;;
        --env-file) env_file="$2"; shift 2 ;;
        --project-name) project_name="$2"; shift 2 ;;
        --create) create=true; shift ;;
        *) usage; exit 64 ;;
    esac
done

[[ -n "$root_dir" && -n "$env_file" && -n "$project_name" ]] || { usage; exit 64; }

root_dir=$(cd "$root_dir" && pwd -P)
env_file="$root_dir/$env_file"
[[ -f "$env_file" ]] || { echo "Missing environment file: $env_file" >&2; exit 64; }

test_database=$(sed -n 's/.*<env name="DB_DATABASE" value="\([^"]*\)".*/\1/p' "$root_dir/phpunit.xml" | head -n 1)
[[ "$test_database" =~ ^[a-z0-9_]+_test$ ]] || { echo "Refusing non-test database: ${test_database:-missing}" >&2; exit 65; }

compose=(docker compose --project-directory "$root_dir" --project-name "$project_name" --env-file "$env_file" -f "$root_dir/docker-compose.yml")
"${compose[@]}" ps --status running postgres | grep -q postgres || { echo 'Postgres is not running.' >&2; exit 66; }
"${compose[@]}" exec -T postgres sh -lc 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"' >/dev/null

if $create; then
    "${compose[@]}" exec -T postgres sh -lc "psql -v ON_ERROR_STOP=1 -U \"\$POSTGRES_USER\" -d postgres -tc \"SELECT 1 FROM pg_database WHERE datname = '$test_database'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" -O \"\$POSTGRES_USER\" '$test_database'"
fi

"${compose[@]}" exec -T postgres sh -lc "psql -U \"\$POSTGRES_USER\" -d postgres -Atqc \"SELECT 1 FROM pg_database WHERE datname = '$test_database'\"" | grep -qx 1 || { echo "Missing test database: $test_database" >&2; exit 67; }
"${compose[@]}" ps --status running app | grep -q app || { echo 'Application container is not running.' >&2; exit 69; }

if ! migration_status=$("${compose[@]}" exec -T -e APP_ENV=testing -e DB_DATABASE="$test_database" app php artisan migrate:status --env=testing --no-interaction); then
    echo "Cannot inspect migrations in $test_database." >&2
    exit 70
fi

if grep -q Pending <<< "$migration_status"; then
    echo "Pending migrations in $test_database." >&2
    exit 68
fi

printf 'Test database ready: %s\n' "$test_database"
