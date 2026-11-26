#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
compose_file="$root_dir/docker-compose.prod.yml"
stage=${1:-application}
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

if [[ "$stage" != "app-base" && "$stage" != "application" && "$stage" != "worker-prepare" && "$stage" != "doctor" && "$stage" != "--config-only" ]]; then
    echo "Expected app-base, application, worker-prepare, doctor, or --config-only" >&2
    exit 2
fi

grep -E '^FROM serversideup/php:8\.5-fpm-nginx@sha256:[0-9a-f]{64} AS app-base$' "$root_dir/docker/php/Dockerfile" >/dev/null
grep -F 'FROM app-base AS application' "$root_dir/docker/php/Dockerfile" >/dev/null
grep -F 'FROM application AS worker-prepare' "$root_dir/docker/php/Dockerfile" >/dev/null
grep -F 'FROM app-base AS doctor' "$root_dir/docker/php/Dockerfile" >/dev/null
bash -n "$root_dir/docker/php/entrypoint.d/10-secrets.sh"
grep -F 'install -d -m 0700 -o www-data -g www-data "$runtime_dir"' "$root_dir/docker/php/entrypoint.d/10-secrets.sh" >/dev/null
grep -F 'chown www-data:www-data "$runtime_file"' "$root_dir/docker/php/entrypoint.d/10-secrets.sh" >/dev/null
grep -F 'chmod 0600 "$runtime_file"' "$root_dir/docker/php/entrypoint.d/10-secrets.sh" >/dev/null
! grep -Eq '^container_name:' "$compose_file"
! grep -Eq '^[[:space:]]+- \./:/var/www/html' "$compose_file"
! grep -Eq '/var/www/html/vendor' "$compose_file"
! grep -Eq 'image: .*latest' "$compose_file"

cat > "$temp_dir/.env" <<'EOF'
COMPOSE_PROJECT_NAME=agelto_contract
IMAGE_TAG=contract-tag
APP_URL=http://localhost:8080
ADMIN_EMAIL=ops@example.test
DB_DATABASE=agelto
DB_USERNAME=agelto
APP_DEBUG=false
EOF
env -u IMAGE_TAG -u IMAGE_REGISTRY docker compose --project-directory "$root_dir" --project-name agelto_p1_image_contract \
    --env-file "$temp_dir/.env" --profile '*' -f "$compose_file" config > "$temp_dir/compose.yml"
grep -F 'image: agelto/app:contract-tag' "$temp_dir/compose.yml" | wc -l | grep -qx '6'
grep -F 'image: agelto/worker-prepare:contract-tag' "$temp_dir/compose.yml" | wc -l | grep -qx '1'
grep -F 'image: agelto/postgres:contract-tag' "$temp_dir/compose.yml" | wc -l | grep -qx '1'
grep -F 'image: agelto/doctor:contract-tag' "$temp_dir/compose.yml" | wc -l | grep -qx '1'
! grep -Eq '^[[:space:]]+build:' "$compose_file"
grep -F 'no-new-privileges:true' "$temp_dir/compose.yml" | wc -l | grep -qx '13'

env -u COMPOSE_PROJECT_NAME -u IMAGE_TAG IMAGE_REGISTRY=registry.example.test \
    docker compose --project-directory "$root_dir" --env-file /dev/null --profile '*' -f "$compose_file" config > "$temp_dir/unconfigured.yml"
grep -Fx 'name: agelto_unconfigured' "$temp_dir/unconfigured.yml" >/dev/null
for image in app worker-prepare doctor postgres; do
    grep -Fx "    image: image-tag-required.invalid/agelto/$image:unconfigured" "$temp_dir/unconfigured.yml" >/dev/null
done

if [[ "$stage" == --config-only ]]; then
    echo 'Compose подставляет диагностические ссылки без тега релиза и сохраняет заданный тег'
    exit 0
fi

sentinel="P1_IMAGE_SECRET_$RANDOM$RANDOM"
image="agelto-contract:$stage-$RANDOM"
docker build --file "$root_dir/docker/php/Dockerfile" --target "$stage" --build-arg "VALIDATION_SENTINEL=$sentinel" -t "$image" "$root_dir"
image_user=$(docker image inspect --format '{{.Config.User}}' "$image")
[[ -z "$image_user" || "$image_user" == root ]]
docker run --rm --entrypoint sh "$image" -c 'test -x /etc/entrypoint.d/10-secrets.sh && grep -F "exec /usr/local/bin/docker-php-serversideup-entrypoint" /etc/entrypoint.d/10-secrets.sh >/dev/null'
docker run --rm --entrypoint php "$image" -v | grep -q '^PHP '
if [[ "$stage" == application ]]; then
    docker run --rm --entrypoint sh "$image" -ec 'if command -v libreoffice || command -v tesseract; then exit 1; fi'
    install -m 0600 /dev/null "$temp_dir/database.sqlite"
    docker run --rm --entrypoint php -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/database.sqlite -v "$temp_dir/database.sqlite:/tmp/database.sqlite" "$image" artisan --version | grep -q '^Laravel Framework '
    docker run --rm --entrypoint sh "$image" -c 'test -f public/vendor/livewire/manifest.json && test -f public/js/filament/filament/app.js && test -f public/css/filament/filament/app.css'
fi
if [[ "$stage" == worker-prepare ]]; then
    docker run --rm --entrypoint sh "$image" -ec 'command -v libreoffice && command -v tesseract && command -v ocrmypdf && command -v qpdf'
fi
! docker image history --no-trunc "$image" | grep -F "$sentinel" >/dev/null
container_name="agelto-p1-image-contract-$RANDOM"
docker create --name "$container_name" "$image" >/dev/null
container=$(docker container inspect --format '{{.Id}}' "$container_name")
trap 'docker rm -f "$container" >/dev/null 2>&1 || true; rm -rf "$temp_dir"' EXIT
docker export "$container" > "$temp_dir/image.tar"
! tar -tf "$temp_dir/image.tar" | grep -Eq '(^|/)(\.env|auth\.json|plans|\.ai|\.claude|bootstrap/cache)(/|$)'
docker rm -f "$container" >/dev/null

echo "Production image contract passed ($stage)"
