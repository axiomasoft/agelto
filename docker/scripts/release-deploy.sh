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
    docker pull "agelto/doctor:$IMAGE_TAG"
    app_labels=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}:{{index .Config.Labels "io.agelto.vendor-lock-sha"}}' "agelto/app:$IMAGE_TAG")
    doctor_labels=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}:{{index .Config.Labels "io.agelto.vendor-lock-sha"}}' "agelto/doctor:$IMAGE_TAG")
    if [[ -n "$app_labels" || -n "$doctor_labels" || -n "${EXPECTED_SOURCE_SHA:-}" ]]; then
        if [[ -z "$app_labels" || "$app_labels" != *:* || "${app_labels#:}" != "$app_labels" || "${app_labels%:}" != "$app_labels" ]]; then
            echo "Image agelto/app:$IMAGE_TAG is missing required revision or vendor-lock labels" >&2
            exit 2
        fi
        if [[ "$app_labels" != "$doctor_labels" ]]; then
            echo "Image pair agelto/app:$IMAGE_TAG and agelto/doctor:$IMAGE_TAG label mismatch" >&2
            exit 2
        fi
        if [[ -n "${EXPECTED_SOURCE_SHA:-}" && "${app_labels%%:*}" != "$EXPECTED_SOURCE_SHA" ]]; then
            echo "Image agelto/app:$IMAGE_TAG revision does not match EXPECTED_SOURCE_SHA" >&2
            exit 2
        fi
    fi
    exit 0
fi

# Every invalidation class consumes an already promoted immutable image. Production hosts never
# build application images: the class selects operator intent/preflight, not a host-side build path.
services=(app horizon scheduler process-relay process-reconciler process-feeder process-projector)
"${compose[@]}" pull "${services[@]}"
"${compose[@]}" up -d --no-build --remove-orphans "${services[@]}"
"${compose[@]}" exec -T app php artisan app:readiness --format=agent
