#!/usr/bin/env bash

set -euo pipefail

mode=''
env_file=''

while [[ $# -gt 0 ]]; do
    case "$1" in
        --mode) mode="$2"; shift 2 ;;
        --env-file) env_file="$2"; shift 2 ;;
        *) echo "Unknown argument: $1" >&2; exit 64 ;;
    esac
done

case "$mode" in
    dev) compose_file='docker-compose.yml' ;;
    prod) compose_file='docker-compose.prod.yml' ;;
    *) echo 'MODE must be dev or prod' >&2; exit 64 ;;
esac

[[ -n "$env_file" && -f "$env_file" ]] || { echo "Environment file is required: $env_file" >&2; exit 1; }
command -v docker >/dev/null || { echo 'docker executable is required' >&2; exit 1; }
docker compose version >/dev/null

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
MINIO_ROOT_PASSWORD=preflight-placeholder \
    docker compose --project-directory "$root_dir" --env-file "$env_file" -f "$root_dir/$compose_file" config >/dev/null
echo "Preflight passed for $mode using $env_file"
