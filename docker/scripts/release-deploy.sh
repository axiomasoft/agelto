#!/usr/bin/env bash

set -euo pipefail

deploy_class=${1:?"deploy class is required"}
root_dir=${2:?"repository root is required"}
env_file=${3:?"environment file is required"}

case "$deploy_class" in
    code|php-deps|frontend|runtime|image-promotion) ;;
    *)
        echo "Unsupported deploy class: $deploy_class" >&2
        exit 64
        ;;
esac

: "${IMAGE_TAG:?IMAGE_TAG is required}"
: "${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}"

if [[ "$IMAGE_TAG" == 'latest' ]]; then
    echo 'IMAGE_TAG=latest is forbidden for production deploys' >&2
    exit 2
fi

if [[ ! -f "$root_dir/$env_file" ]]; then
    echo "Environment file is required: $env_file" >&2
    exit 2
fi

compose=(
    docker compose
    --project-directory "$root_dir"
    --project-name "$COMPOSE_PROJECT_NAME"
    --env-file "$root_dir/$env_file"
    -f "$root_dir/docker-compose.prod.yml"
)

"${compose[@]}" config >/dev/null

if [[ "$deploy_class" == 'image-promotion' ]]; then
    docker pull "agelto/app:$IMAGE_TAG"
    exit 0
fi

# Every invalidation class consumes an already promoted immutable image. Production hosts never
# build application images: the class selects operator intent/preflight, not a host-side build path.
"${compose[@]}" pull app horizon scheduler
"${compose[@]}" up -d --no-build --remove-orphans app horizon scheduler
