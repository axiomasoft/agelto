#!/usr/bin/env bash

set -euo pipefail

root=''
tag=''
registry=''
publish=false

usage() {
    echo 'usage: image-pair.sh --root <repository> --tag <immutable-tag> [--registry <host:port> --publish]' >&2
}

while (($#)); do
    case "$1" in
        --root) root="$2"; shift 2 ;;
        --tag) tag="$2"; shift 2 ;;
        --registry) registry="$2"; shift 2 ;;
        --publish) publish=true; shift ;;
        *) usage; exit 2 ;;
    esac
done

[ -n "$root" ] && [ -n "$tag" ] || { usage; exit 2; }
[ "$tag" != latest ] || { echo 'latest tag is forbidden' >&2; exit 2; }
[[ "$tag" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo 'tag is unsafe' >&2; exit 2; }
[[ -z "$registry" || "$registry" =~ ^[A-Za-z0-9.-]+:[0-9]+$ ]] || { echo 'registry must be host:port' >&2; exit 2; }
[[ "$publish" == false || -n "$registry" ]] || { echo '--publish requires --registry for an isolated registry' >&2; exit 2; }

root="$(cd "$root" && pwd -P)"
git -C "$root" diff --quiet -- . ':(exclude)plans/**' ':(exclude)AGENTS.md' \
    && git -C "$root" diff --cached --quiet -- . ':(exclude)plans/**' ':(exclude)AGENTS.md' || {
    echo 'image pair requires a clean tracked product source tree' >&2
    exit 3
}

bash "$root/docker/scripts/vendor-sync.sh" --check
(
    cd "$root"
    composer install --dry-run --no-dev --no-scripts --no-interaction --no-progress --prefer-dist
)
source_sha="$(git -C "$root" rev-parse HEAD)"
vendor_lock_sha="$(sha256sum "$root/docker/private-vendors.lock.json" | awk '{print $1}')"

build_image() {
    local target="$1" image="$2"
    docker build \
        --target "$target" \
        --build-arg "SOURCE_SHA=$source_sha" \
        --build-arg "VENDOR_LOCK_SHA=$vendor_lock_sha" \
        --tag "$image" \
        --file "$root/docker/php/Dockerfile" \
        "$root"
    [ "$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$image")" = "$source_sha" ]
    [ "$(docker image inspect --format '{{ index .Config.Labels "io.agelto.vendor-lock-sha" }}' "$image")" = "$vendor_lock_sha" ]
}

build_postgres_image() {
    local image="$1"
    docker build \
        --build-arg "SOURCE_SHA=$source_sha" \
        --tag "$image" \
        --file "$root/docker/postgres/Dockerfile" \
        "$root/docker/postgres"
    [ "$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$image")" = "$source_sha" ]
}

image_prefix="${registry:+$registry/}agelto"
app_image="$image_prefix/app:$tag"
doctor_image="$image_prefix/doctor:$tag"
postgres_image="$image_prefix/postgres:$tag"
build_image application "$app_image"
build_image doctor "$doctor_image"
build_postgres_image "$postgres_image"

docker run --rm --entrypoint sh "$app_image" -c 'test ! -d vendor/pestphp && test ! -d vendor/laravel/doctor'
docker run --rm --entrypoint sh "$doctor_image" -c 'test -d vendor/pestphp && test -d vendor/laravel/doctor'

if "$publish"; then
    docker push "$app_image"
    docker push "$doctor_image"
    docker push "$postgres_image"

    docker image rm "$app_image" "$doctor_image" "$postgres_image" >/dev/null
    docker pull "$app_image" >/dev/null
    docker pull "$doctor_image" >/dev/null
    docker pull "$postgres_image" >/dev/null
    docker run --rm --entrypoint sh "$app_image" -c 'test ! -d vendor/pestphp && test ! -d vendor/laravel/doctor'
    docker run --rm --entrypoint sh "$doctor_image" -c 'test -d vendor/pestphp && test -d vendor/laravel/doctor'
    [ "$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$postgres_image")" = "$source_sha" ]
fi

printf 'Image pair ready: %s %s %s\n' "$app_image" "$doctor_image" "$postgres_image"
