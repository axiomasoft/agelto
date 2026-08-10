#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
compose_file="$root_dir/docker-compose.prod.yml"
stage=${1:-application}
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
docker compose --project-directory "$root_dir" --project-name agelto_p1_image_contract --env-file "$temp_dir/.env" -f "$compose_file" config > "$temp_dir/compose.yml"
grep -F 'image: agelto/app:contract-tag' "$temp_dir/compose.yml" | wc -l | grep -qx '3'

sentinel="P1_IMAGE_SECRET_$RANDOM$RANDOM"
image="agelto-contract:$stage-$RANDOM"
docker build --file "$root_dir/docker/php/Dockerfile" --target "$stage" --build-arg "VALIDATION_SENTINEL=$sentinel" -t "$image" "$root_dir"
docker image inspect --format '{{.Config.User}}' "$image" | grep -qx 'www-data'
docker run --rm --entrypoint php "$image" -v | grep -q '^PHP '
! docker image history --no-trunc "$image" | grep -F "$sentinel" >/dev/null
container_name="agelto-p1-image-contract-$RANDOM"
docker create --name "$container_name" "$image" >/dev/null
container=$(docker container inspect --format '{{.Id}}' "$container_name")
trap 'docker rm -f "$container" >/dev/null 2>&1 || true; rm -rf "$temp_dir"' EXIT
docker export "$container" > "$temp_dir/image.tar"
! tar -tf "$temp_dir/image.tar" | grep -Eq '(^|/)(\.env|auth\.json|plans|\.ai|\.claude|bootstrap/cache)(/|$)'
docker rm -f "$container" >/dev/null

echo "Production image contract passed ($stage)"
