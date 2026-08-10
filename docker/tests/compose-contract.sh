#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
compose_file="$root_dir/docker-compose.yml"
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

cat > "$temp_dir/.env" <<'EOF'
APP_NAME=agelto-contract
APP_ENV=testing
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_DEBUG=false
DB_DATABASE=agelto_contract
DB_USERNAME=agelto
DB_PASSWORD=agelto
REDIS_PASSWORD=
MINIO_ROOT_USER=agelto
MINIO_ROOT_PASSWORD=contract-password
EOF

rendered="$temp_dir/compose.yml"
COMPOSE_PROJECT_NAME=agelto_contract \
    docker compose --project-directory "$root_dir" --env-file "$temp_dir/.env" -f "$compose_file" config > "$rendered"

grep -F 'target: development' "$rendered" >/dev/null
grep -F 'condition: service_healthy' "$rendered" >/dev/null
grep -F 'pg_isready -U $$POSTGRES_USER -d $$POSTGRES_DB' "$compose_file" >/dev/null

if grep -q 'container_name:' "$compose_file"; then
    echo 'Compose contract failed: fixed container_name is forbidden' >&2
    exit 1
fi

echo 'Dev Compose contract passed'
