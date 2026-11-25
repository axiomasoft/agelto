#!/usr/bin/env bash

set -euo pipefail

mode=''
env_file=''
root_dir=''
agent_json=false
declare -a checks=()

usage() { echo 'usage: preflight.sh --root <repository> --mode <dev|prod-build|prod-runtime> --env-file <path> [--agent-json]' >&2; }

while (($#)); do
    case "$1" in
        --root) root_dir="$2"; shift 2 ;;
        --mode) mode="$2"; shift 2 ;;
        --env-file) env_file="$2"; shift 2 ;;
        --agent-json) agent_json=true; shift ;;
        *) usage; exit 64 ;;
    esac
done

[[ -n "$root_dir" && -n "$env_file" ]] || { usage; exit 64; }
root_dir=$(cd "$root_dir" && pwd -P)
env_file=$(cd "$(dirname "$env_file")" && pwd -P)/$(basename "$env_file")
case "$mode" in
    dev) compose_file='docker-compose.yml' ;;
    prod-build|prod-runtime) compose_file='docker-compose.prod.yml' ;;
    *) echo 'MODE must be dev, prod-build, or prod-runtime' >&2; exit 64 ;;
esac

report() {
    local status=$1 item first=true payload='['
    if $agent_json; then
        for item in "${checks[@]}"; do
            if $first; then first=false; else payload+=','; fi
            payload+="$item"
        done
        printf '{"checks":%s],"exit_code":%s}\n' "$payload" "$status"
    else
        printf 'Preflight %s (%s)\n' "$([[ $status -eq 0 ]] && echo passed || echo failed)" "$mode"
    fi
    exit "$status"
}

pass() {
    checks+=("{\"id\":\"$1\",\"status\":\"pass\",\"remediation\":\"$2\"}")
    $agent_json || printf 'PASS %s\n' "$1"
}

fail() {
    checks+=("{\"id\":\"$1\",\"status\":\"fail\",\"remediation\":\"$2\"}")
    $agent_json || printf 'FAIL %s: %s\n' "$1" "$2" >&2
    report "$3"
}

fingerprint() {
    { docker ps -aq --no-trunc; docker images -aq --no-trunc; docker volume ls -q; } | LC_ALL=C sort | sha256sum | awk '{print $1}'
}

for tool in bash git python3 docker; do
    command -v "$tool" >/dev/null 2>&1 || fail HOST_TOOL "install required host tool: $tool" 10
done
docker compose version >/dev/null 2>&1 || fail COMPOSE_V2 'install Docker Compose v2' 10
docker info --format '{{.ServerVersion}}' >/dev/null 2>&1 || fail DOCKER_DAEMON 'start the local Docker daemon' 10
pass HOST_TOOLS 'host tools and the P1 Compose-v2 capability are available'

[[ -f "$root_dir/docker/private-vendors.lock.json" ]] || fail VENDOR_MANIFEST 'restore the tracked vendor lock manifest' 11
bash "$root_dir/docker/scripts/vendor-sync.sh" --check >/dev/null 2>&1 || fail VENDOR_LOCK 'run make vendor:sync and resolve origin, SHA, or dirty package paths' 11
pass VENDOR_LOCK 'private vendor pin is present, clean, and matches its manifest'

[[ -f "$env_file" ]] || fail ENV_FILE 'provide an existing non-secret environment file' 12
parsed=$(mktemp)
trap 'rm -f "$parsed"' EXIT
awk '/^[[:space:]]*($|#)/ { next } !/^[A-Za-z_][A-Za-z0-9_]*=/ { exit 1 } { print $0 }' "$env_file" >"$parsed" || fail ENV_SCHEMA 'use KEY=value lines without shell syntax' 12
for key in APP_NAME APP_ENV APP_DEBUG DB_DATABASE DB_USERNAME APP_PORT PGSQL_PORT REDIS_PORT MINIO_PORT MINIO_CONSOLE_PORT APP_KEY PROXYMA_WIRE_DIGEST_KEY DB_PASSWORD REDIS_PASSWORD AWS_SECRET_ACCESS_KEY MINIO_ROOT_PASSWORD MINIO_APP_PASSWORD; do
    count=$(grep -c "^${key}=" "$parsed" || true)
    (( count <= 1 )) || fail ENV_DUPLICATE "keep exactly one $key entry" 12
done
for key in APP_NAME APP_ENV APP_DEBUG DB_DATABASE DB_USERNAME; do
    grep -q "^${key}=" "$parsed" || fail ENV_SCHEMA "add required non-secret key: $key" 12
done
for key in APP_KEY PROXYMA_WIRE_DIGEST_KEY DB_PASSWORD REDIS_PASSWORD AWS_SECRET_ACCESS_KEY MINIO_ROOT_PASSWORD MINIO_APP_PASSWORD; do
    value=$(awk -F= -v wanted="$key" '$1 == wanted { sub(/^[^=]*=/, ""); print; exit }' "$parsed")
    [[ -z "$value" || "$value" == null ]] || fail ENV_SECRET "move $key from the env file into local/secrets" 12
done
grep -Eqi '=(change-me|changeme|placeholder|replace-me|example|secret)$' "$parsed" && fail ENV_PLACEHOLDER 'replace placeholder non-secret configuration values' 12 || :
if [[ "$mode" != dev ]]; then
    for key in APP_URL IMAGE_TAG COMPOSE_PROJECT_NAME; do
        grep -q "^${key}=[^[:space:]]" "$parsed" || fail ENV_SCHEMA "add required production key: $key" 12
    done
fi
if [[ "$mode" == prod-runtime ]]; then
    grep -qx 'APP_ENV=production' "$parsed" || fail ENV_SCHEMA 'set APP_ENV=production for prod-runtime' 12
    grep -qx 'APP_DEBUG=false' "$parsed" || fail ENV_SCHEMA 'set APP_DEBUG=false for prod-runtime' 12
    grep -Eq '^APP_URL=https://[^[:space:]]+$' "$parsed" || fail ENV_SCHEMA 'set an https:// APP_URL for prod-runtime' 12
fi
rm -f "$parsed"
trap - EXIT
pass ENV_SCHEMA 'environment schema is non-secret and complete'

secrets_dir="$root_dir/local/secrets"
[[ -d "$secrets_dir" && ! -L "$secrets_dir" ]] || fail SECRET_DIRECTORY 'create local/secrets as a real directory' 13
mode_bits=$(stat -c '%a' "$secrets_dir")
(( (8#$mode_bits & 077) == 0 )) || fail SECRET_DIRECTORY 'restrict local/secrets to its owner' 13
while IFS= read -r secret; do
    [[ -n "$secret" && "${secret#\#}" == "$secret" ]] || continue
    [[ -f "$secrets_dir/$secret" && ! -L "$secrets_dir/$secret" && -s "$secrets_dir/$secret" ]] || fail SECRET_FILE "create regular non-empty secret file: $secret" 13
    mode_bits=$(stat -c '%a' "$secrets_dir/$secret")
    (( (8#$mode_bits & 077) == 0 )) || fail SECRET_PERMISSION "restrict secret file permissions: $secret" 13
done < "$root_dir/docker/secrets.contract"
grep -q 'file: ./local/secrets/app_key' "$root_dir/$compose_file" || fail SECRET_GRANT 'declare Compose file-secret grants' 13
grep -q 'file: ./local/secrets/proxyma_wire_digest_key' "$root_dir/$compose_file" || fail SECRET_GRANT 'declare proxyma_wire_digest_key Compose file-secret grant' 13
grep -q 'PROXYMA_WIRE_DIGEST_KEY_FILE: /run/secrets/proxyma_wire_digest_key' "$root_dir/$compose_file" || fail SECRET_GRANT 'grant proxyma_wire_digest_key through its file path' 13
grep -q 'file: ./local/secrets/minio_app_password' "$root_dir/$compose_file" || fail SECRET_GRANT 'declare minio_app_password Compose file-secret grant' 13
grep -q 'POSTGRES_PASSWORD_FILE: /run/secrets/db_password' "$root_dir/$compose_file" || fail SECRET_GRANT 'grant db_password through its file path' 13
grep -q 'MINIO_ROOT_PASSWORD_FILE: /run/secrets/minio_root_password' "$root_dir/$compose_file" || fail SECRET_GRANT 'grant minio_root_password through its file path' 13
grep -q 'AWS_SECRET_ACCESS_KEY_FILE: /run/secrets/minio_app_password' "$root_dir/$compose_file" || fail SECRET_GRANT 'grant minio_app_password through its file path' 13
! grep -q 'AWS_SECRET_ACCESS_KEY_FILE: /run/secrets/minio_root_password' "$root_dir/$compose_file" || fail SECRET_GRANT 'do not grant minio_root_password as AWS_SECRET_ACCESS_KEY_FILE' 13
pass SECRET_CONTRACT 'secret files and Compose grants are private and complete'

env_value() { awk -F= -v wanted="$1" '$1 == wanted { sub(/^[^=]*=/, ""); print; exit }' "$env_file"; }
for name in APP_PORT PGSQL_PORT REDIS_PORT MINIO_PORT MINIO_CONSOLE_PORT; do
    port=$(env_value "$name")
    [[ -n "$port" ]] || continue
    [[ "$port" =~ ^[1-9][0-9]{0,4}$ ]] && (( port <= 65535 )) || fail PORT_VALUE "set a valid $name value" 14
    python3 - "$port" <<'PY' || fail PORT_OCCUPIED "free required host port: $name" 14
import socket
import sys
s = socket.socket()
try:
    s.bind(("127.0.0.1", int(sys.argv[1])))
finally:
    s.close()
PY
done
[[ -d "$root_dir/storage" && -w "$root_dir/storage" ]] || fail STORAGE 'create writable repository storage directory' 14
pass HOST_RESOURCES 'required ports are free and storage is writable'

before=$(fingerprint)
project_name=$(env_value COMPOSE_PROJECT_NAME)
docker compose --project-directory "$root_dir" --project-name "${project_name:-agelto_preflight}" --env-file "$env_file" -f "$root_dir/$compose_file" config --quiet >/dev/null 2>&1 || fail COMPOSE_CONFIG 'fix the selected Compose file or non-secret environment configuration' 15
after=$(fingerprint)
[[ "$before" == "$after" ]] || fail DOCKER_MUTATION 'preflight must not create or change Docker resources' 16
pass COMPOSE_CONFIG 'Compose renders quietly without mutating Docker state'
report 0
