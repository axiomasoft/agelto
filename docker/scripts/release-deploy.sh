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
    registry=$("${compose[@]}" config --environment | sed -n 's/^IMAGE_REGISTRY=//p')
    [[ -z "$registry" || ( "$registry" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*(:[0-9]+)?$ \
        && ( "$registry" == *.* || "$registry" == *:* || "$registry" == localhost ) ) ]] || {
        echo 'IMAGE_REGISTRY must be a qualified hostname or host:port' >&2
        exit 2
    }
    prefix="${registry:+$registry/}agelto"
    app_image="$prefix/app:$IMAGE_TAG"
    worker_image="$prefix/worker-prepare:$IMAGE_TAG"
    doctor_image="$prefix/doctor:$IMAGE_TAG"
    postgres_image="$prefix/postgres:$IMAGE_TAG"
    for image in "$app_image" "$worker_image" "$doctor_image" "$postgres_image"; do
        docker pull "$image"
    done

    revision_label='{{index .Config.Labels "org.opencontainers.image.revision"}}'
    image_labels="$revision_label:{{index .Config.Labels \"io.agelto.vendor-lock-sha\"}}"
    app_labels=$(docker image inspect --format "$image_labels" "$app_image")
    if [[ ! "$app_labels" =~ ^[0-9a-f]{40}:[0-9a-f]{64}$ ]]; then
        echo "Image $app_image is missing required revision or vendor-lock labels" >&2
        exit 2
    fi
    for image in "$worker_image" "$doctor_image"; do
        if [[ "$(docker image inspect --format "$image_labels" "$image")" != "$app_labels" ]]; then
            echo "Image $image revision or vendor-lock label mismatch" >&2
            exit 2
        fi
    done
    if [[ "$(docker image inspect --format "$revision_label" "$postgres_image")" != "${app_labels%%:*}" ]]; then
        echo "Image $postgres_image revision label mismatch" >&2
        exit 2
    fi
    if [[ -n "${EXPECTED_SOURCE_SHA:-}" && "${app_labels%%:*}" != "$EXPECTED_SOURCE_SHA" ]]; then
        echo "Image $app_image revision does not match EXPECTED_SOURCE_SHA" >&2
        exit 2
    fi
    exit 0
fi

# Every invalidation class consumes an already promoted immutable image. Production hosts never
# build application images: the class selects operator intent/preflight, not a host-side build path.
services=(app horizon scheduler process-relay process-reconciler process-feeder process-projector)
"${compose[@]}" pull "${services[@]}"
"${compose[@]}" up -d --no-build --wait --remove-orphans "${services[@]}"
"${compose[@]}" exec -T app php artisan app:readiness --format=agent
