#!/usr/bin/env bash

set -euo pipefail

root=''
tag=''
publish=false

usage() {
    echo 'usage: image-pair.sh --root <repository> --tag <immutable-tag> [--publish]' >&2
}

while (($#)); do
    case "$1" in
        --root) root="$2"; shift 2 ;;
        --tag) tag="$2"; shift 2 ;;
        --publish) publish=true; shift ;;
        *) usage; exit 2 ;;
    esac
done

[ -n "$root" ] && [ -n "$tag" ] || { usage; exit 2; }
[ "$tag" != latest ] || { echo 'latest tag is forbidden' >&2; exit 2; }
[[ "$tag" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo 'tag is unsafe' >&2; exit 2; }

root="$(cd "$root" && pwd -P)"
git -C "$root" diff --quiet -- . ':(exclude)plans/**' ':(exclude)AGENTS.md' \
    && git -C "$root" diff --cached --quiet -- . ':(exclude)plans/**' ':(exclude)AGENTS.md' || {
    echo 'image pair requires a clean tracked product source tree' >&2
    exit 3
}

bash "$root/docker/scripts/vendor-sync.sh" --check --composer-check
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

app_image="agelto/app:$tag"
doctor_image="agelto/doctor:$tag"
build_image application "$app_image"
build_image doctor "$doctor_image"

docker run --rm --entrypoint sh "$app_image" -c 'test ! -d vendor/pestphp && test ! -d vendor/laravel/doctor'
docker run --rm --entrypoint sh "$doctor_image" -c 'test -d vendor/pestphp && test -d vendor/laravel/doctor'

if "$publish"; then
    docker push "$app_image"
    docker push "$doctor_image"
fi

printf 'Image pair ready: %s %s\n' "$app_image" "$doctor_image"
