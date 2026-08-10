#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
compose_file="$root_dir/docker-compose.yml"
project_name="agelto_p1_2_$(date +%s)_$RANDOM"
temp_dir=$(mktemp -d)
sentinel=".p1-2-code-sync-$RANDOM"

cleanup() {
    rm -f "$root_dir/$sentinel"
    docker compose --project-directory "$root_dir" --project-name "$project_name" --env-file "$temp_dir/.env" -f "$compose_file" kill >/dev/null 2>&1 || true
    docker compose --project-directory "$root_dir" --project-name "$project_name" --env-file "$temp_dir/.env" -f "$compose_file" down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$temp_dir"
}
trap cleanup EXIT

cat > "$temp_dir/.env" <<'EOF'
APP_NAME=agelto-validation
APP_ENV=testing
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_DEBUG=false
DB_DATABASE=agelto_validation
DB_USERNAME=agelto
DB_PASSWORD=agelto-validation-password
REDIS_PASSWORD=
MINIO_ROOT_USER=agelto
MINIO_ROOT_PASSWORD=agelto-validation-password
APP_PORT=0
PGSQL_PORT=0
REDIS_PORT=0
MINIO_PORT=0
MINIO_CONSOLE_PORT=0
EOF

compose=(docker compose --project-directory "$root_dir" --project-name "$project_name" --env-file "$temp_dir/.env" -f "$compose_file")
"${compose[@]}" up --detach --no-deps --build app

for service in app; do
    container_id=$("${compose[@]}" ps --quiet "$service")
    [[ -n "$container_id" ]] || { echo "Missing validation service: $service" >&2; exit 1; }
    for _ in $(seq 1 10); do
        [[ $(docker inspect --format '{{.State.Running}}' "$container_id") == true ]] && break
        sleep 1
    done
    [[ $(docker inspect --format '{{.State.Running}}' "$container_id") == true ]] || {
        echo "Validation service is not running: $service" >&2
        exit 1
    }
done

image_id_before=$("${compose[@]}" images --quiet app)
[[ -n "$image_id_before" ]] || { echo 'App image ID is unavailable' >&2; exit 1; }

printf 'P1.2 code-sync validation\n' > "$root_dir/$sentinel"
"${compose[@]}" exec -T app test -f "/var/www/html/$sentinel"
"${compose[@]}" exec -T -e AI_AGENT=1 app php -v >/dev/null
"${compose[@]}" kill app >/dev/null
"${compose[@]}" up --detach --no-build app
image_id_after=$("${compose[@]}" images --quiet app)
[[ "$image_id_before" == "$image_id_after" ]] || { echo 'Code-only recreate changed app image ID' >&2; exit 1; }

echo 'Dev code-sync and non-TTY validation passed'
