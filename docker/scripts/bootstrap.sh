#!/usr/bin/env bash

set -euo pipefail

root_dir=''
mode=''
env_file=''
image_tag="${IMAGE_TAG:-}"
project_name="${COMPOSE_PROJECT_NAME:-agelto_bootstrap}"
admin_password_file=''
current_stage='initialization'
progress_file=''
source_sha=''

usage() {
    echo 'usage: bootstrap.sh --root <repository> --mode <dev|prod-build> --env-file <path> [--image-tag <tag>] [--project-name <name>] [--admin-password-file <path>]' >&2
}

while (($#)); do
    case "$1" in
        --root) root_dir="$2"; shift 2 ;;
        --mode) mode="$2"; shift 2 ;;
        --env-file) env_file="$2"; shift 2 ;;
        --image-tag) image_tag="$2"; shift 2 ;;
        --project-name) project_name="$2"; shift 2 ;;
        --admin-password-file) admin_password_file="$2"; shift 2 ;;
        *) usage; exit 64 ;;
    esac
done

[[ -n "$root_dir" && -n "$mode" && -n "$env_file" ]] || { usage; exit 64; }
root_dir=$(cd "$root_dir" && pwd -P)
case "$mode" in
    dev|prod-build) ;;
    *) echo 'MODE must be dev or prod-build' >&2; exit 64 ;;
esac
[[ "$project_name" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || { echo 'project name is invalid' >&2; exit 64; }

source_sha=$(git -C "$root_dir" rev-parse HEAD) || { echo 'Bootstrap requires a Git checkout' >&2; exit 2; }
[[ "$source_sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'Bootstrap source SHA is invalid' >&2; exit 2; }
if [[ -z "$image_tag" ]]; then
    image_tag="bootstrap-${source_sha:0:12}"
fi
if [[ "$mode" == prod-build && "$image_tag" == latest ]]; then
    echo 'prod-build requires an immutable IMAGE_TAG other than latest' >&2
    exit 2
fi

mkdir -p "$root_dir/local/secrets"
progress_file="$root_dir/local/secrets/.bootstrap-progress"

resume_command() {
    printf 'make bootstrap MODE=%q ENV_FILE=%q IMAGE_TAG=%q COMPOSE_PROJECT_NAME=%q' "$mode" "$env_file" "$image_tag" "$project_name"
}

write_progress() {
    local status=$1 temporary
    temporary=$(mktemp "${progress_file}.XXXXXX")
    printf '{"stage":"%s","status":"%s","source_sha":"%s"}\n' "$current_stage" "$status" "$source_sha" > "$temporary"
    chmod 600 "$temporary"
    mv -f "$temporary" "$progress_file"
}

on_error() {
    local status=$1
    write_progress failed
    printf 'Bootstrap failed at stage %s. Resume: ' "$current_stage" >&2
    resume_command >&2
    printf '\n' >&2
    exit "$status"
}

run_stage() {
    current_stage=$1
    shift
    write_progress running
    "$@" || on_error "$?"
    write_progress passed
}

run_make() {
    make -C "$root_dir" -f docker/Makefile "$@"
}

run_stage vendor run_make vendor:sync
run_stage secrets run_make secrets:init
run_stage preflight run_make infra:preflight "MODE=$mode" "ENV_FILE=$env_file"
run_stage dependencies-image run_make image:build "IMAGE_TAG=$image_tag"

if [[ "$mode" == dev ]]; then
    run_stage dev-up run_make dev:up "ENV_FILE=$env_file" "COMPOSE_PROJECT_NAME=$project_name"
    run_stage dev-dependencies run_make dev:composer 'ARGS=install --no-interaction --no-progress' "ENV_FILE=$env_file" "COMPOSE_PROJECT_NAME=$project_name"
    run_stage dev-migrate run_make dev:migrate "ENV_FILE=$env_file" "COMPOSE_PROJECT_NAME=$project_name"
    run_stage dev-base-seed run_make dev:seed-base "ENV_FILE=$env_file" "COMPOSE_PROJECT_NAME=$project_name"

    if [[ -n "$admin_password_file" ]]; then
        [[ -r "$admin_password_file" ]] || { echo 'Initial admin password file is not readable' >&2; exit 2; }
        run_stage dev-admin-create run_make dev:admin-create "PASSWORD_FILE=$admin_password_file" "ENV_FILE=$env_file" "COMPOSE_PROJECT_NAME=$project_name"
    fi

    current_stage='dev-health'
    write_progress running
    for _ in $(seq 1 30); do
        if run_make dev:health "ENV_FILE=$env_file" "COMPOSE_PROJECT_NAME=$project_name"; then
            write_progress passed
            printf 'Bootstrap completed: dev stack is healthy.\n'
            exit 0
        fi
        sleep 2
    done
    on_error 1
fi

run_stage prod-image-contract run_make prod:config "ENV_FILE=$env_file" "IMAGE_TAG=$image_tag" "COMPOSE_PROJECT_NAME=$project_name"
printf 'Bootstrap completed: immutable production image was built and its Compose contract rendered.\n'
