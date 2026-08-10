#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
compose_file="$root_dir/docker-compose.prod.yml"
stage=${1:-runtime-base}
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

if [[ "$stage" != "runtime-base" && "$stage" != "application" ]]; then
    echo "Expected runtime-base or application stage" >&2
    exit 2
fi

grep -F 'FROM serversideup/php:8.5-fpm-nginx AS runtime-base' "$root_dir/docker/php/Dockerfile" >/dev/null
grep -F 'FROM runtime-base AS application' "$root_dir/docker/php/Dockerfile" >/dev/null
grep -F 'USER www-data' "$root_dir/docker/php/Dockerfile" >/dev/null
! grep -Eq '^container_name:' "$compose_file"
! grep -Eq '^[[:space:]]+- \./:/var/www/html' "$compose_file"
! grep -Eq '/var/www/html/vendor' "$compose_file"
! grep -Eq 'image: .*latest' "$compose_file"

cat > "$temp_dir/.env" <<'EOF'
COMPOSE_PROJECT_NAME=agelto_contract
IMAGE_TAG=contract-tag
APP_URL=http://localhost:8080
DB_DATABASE=agelto
DB_USERNAME=agelto
APP_DEBUG=false
EOF
docker compose --project-directory "$root_dir" --env-file "$temp_dir/.env" -f "$compose_file" config > "$temp_dir/compose.yml"
grep -F 'image: agelto/app:contract-tag' "$temp_dir/compose.yml" | wc -l | grep -qx '3'

docker build --file "$root_dir/docker/php/Dockerfile" --target "$stage" -t "agelto-contract:$stage" "$root_dir"
docker image inspect --format '{{.Config.User}}' "agelto-contract:$stage" | grep -qx 'www-data'
docker run --rm --entrypoint php "agelto-contract:$stage" -v | grep -q '^PHP '

echo "Production image contract passed ($stage)"
