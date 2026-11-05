#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
repo="$tmp/repo"
mkdir -p "$repo/docker/scripts" "$repo/local/secrets" "$repo/storage" "$tmp/bin"

cp "$root_dir/docker/scripts/preflight.sh" "$repo/docker/scripts/preflight.sh"
cp "$root_dir/docker/secrets.contract" "$repo/docker/secrets.contract"
cp "$root_dir/docker/private-vendors.lock.json" "$repo/docker/private-vendors.lock.json"
cp "$root_dir/docker-compose.yml" "$repo/docker-compose.yml"
cp "$root_dir/docker-compose.prod.yml" "$repo/docker-compose.prod.yml"

cat > "$repo/docker/scripts/vendor-sync.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

test "${VENDOR_FAIL:-0}" != 1
test "$1" = --check
EOF
chmod +x "$repo/docker/scripts/vendor-sync.sh"

cat > "$tmp/bin/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${DOCKER_LOG:?}"
case "$1 ${2:-}" in
    'compose version') test "${FAIL_COMPOSE_V2:-0}" != 1; exit ;;
    'info --format'|'ps -aq'|'images -aq') exit 0 ;;
    'volume ls')
        if [[ -n "${DOCKER_STATE_FILE:-}" ]]; then
            count=$(cat "$DOCKER_STATE_FILE" 2>/dev/null || echo 0)
            count=$((count + 1)); printf '%s' "$count" > "$DOCKER_STATE_FILE"
            [[ "${MUTATE_STATE:-0}" != 1 || "$count" -lt 2 ]] || printf 'changed\n'
        fi
        exit 0 ;;
esac
if [[ "$1" == compose && " $* " == *' config --quiet '* ]]; then test "${FAIL_COMPOSE:-0}" != 1; exit; fi
echo "unexpected docker argv: $*" >&2
exit 64
EOF
chmod +x "$tmp/bin/docker"

cat > "$repo/preflight.env" <<'EOF'
APP_NAME=agelto
APP_ENV=testing
APP_DEBUG=false
DB_DATABASE=agelto_test
DB_USERNAME=agelto
APP_PORT=38180
PGSQL_PORT=38181
REDIS_PORT=38182
MINIO_PORT=38183
MINIO_CONSOLE_PORT=38184
COMPOSE_PROJECT_NAME=agelto_preflight
IMAGE_TAG=preflight-test
APP_URL=http://localhost:38180
LOG_LEVEL=warning
EOF
while IFS= read -r secret; do
    [[ -n "$secret" && "${secret#\#}" == "$secret" ]] || continue
    printf 'fixture-%s\n' "$secret" > "$repo/local/secrets/$secret"
    chmod 600 "$repo/local/secrets/$secret"
done < "$repo/docker/secrets.contract"
chmod 700 "$repo/local/secrets"

run() {
    PATH="$tmp/bin:$PATH" DOCKER_LOG="$tmp/docker.argv" bash "$repo/docker/scripts/preflight.sh" --root "$repo" "$@"
}

expect_failure() {
    local expected=$1 actual
    shift
    set +e
    "$@" >/dev/null 2>&1
    actual=$?
    set -e
    [[ "$actual" -eq "$expected" ]]
}

bash -n "$root_dir/docker/scripts/preflight.sh" "$root_dir/docker/tests/preflight-contract.sh"
json=$(run --mode dev --env-file "$repo/preflight.env" --agent-json)
printf '%s' "$json" | python3 -c 'import json,sys; x=json.load(sys.stdin); assert x["exit_code"] == 0; assert all(set(i) == {"id", "status", "remediation"} for i in x["checks"])'
! printf '%s' "$json" | grep -q 'fixture-app_key'
run --mode prod-build --env-file "$repo/preflight.env" >/dev/null
expect_failure 12 run --mode prod-runtime --env-file "$repo/preflight.env"
sed \
    -e 's/^APP_ENV=.*/APP_ENV=production/' \
    -e 's#^APP_URL=.*#APP_URL=https://agelto.example.test#' \
    "$repo/preflight.env" > "$repo/prod-runtime.env"
run --mode prod-runtime --env-file "$repo/prod-runtime.env" >/dev/null
sed -e 's/^APP_DEBUG=.*/APP_DEBUG=true/' "$repo/prod-runtime.env" > "$repo/prod-runtime-debug.env"
expect_failure 12 run --mode prod-runtime --env-file "$repo/prod-runtime-debug.env"

expect_failure 64 run --mode unknown --env-file "$repo/preflight.env"
printf 'APP_NAME=agelto\nAPP_NAME=placeholder\n' > "$repo/bad.env"
expect_failure 12 run --mode dev --env-file "$repo/bad.env"
chmod 644 "$repo/local/secrets/app_key"
expect_failure 13 run --mode dev --env-file "$repo/preflight.env"
chmod 600 "$repo/local/secrets/app_key"
expect_failure 11 env VENDOR_FAIL=1 bash -c 'PATH="$1:$PATH" DOCKER_LOG="$2" VENDOR_FAIL=1 bash "$3/docker/scripts/preflight.sh" --root "$3" --mode dev --env-file "$3/preflight.env"' _ "$tmp/bin" "$tmp/docker.argv" "$repo"
expect_failure 10 env FAIL_COMPOSE_V2=1 bash -c 'PATH="$1:$PATH" DOCKER_LOG="$2" FAIL_COMPOSE_V2=1 bash "$3/docker/scripts/preflight.sh" --root "$3" --mode dev --env-file "$3/preflight.env"' _ "$tmp/bin" "$tmp/docker.argv" "$repo"

python3 -c 'import socket,time; s=socket.socket(); s.bind(("127.0.0.1",38180)); s.listen(); time.sleep(10)' &
listener=$!
sleep 0.1
expect_failure 14 run --mode dev --env-file "$repo/preflight.env"
kill "$listener" 2>/dev/null || true
wait "$listener" 2>/dev/null || true
expect_failure 15 env FAIL_COMPOSE=1 bash -c 'PATH="$1:$PATH" DOCKER_LOG="$2" FAIL_COMPOSE=1 bash "$3/docker/scripts/preflight.sh" --root "$3" --mode dev --env-file "$3/preflight.env"' _ "$tmp/bin" "$tmp/docker.argv" "$repo"
expect_failure 16 env DOCKER_STATE_FILE="$tmp/state" MUTATE_STATE=1 bash -c 'PATH="$1:$PATH" DOCKER_LOG="$2" DOCKER_STATE_FILE="$4" MUTATE_STATE=1 bash "$3/docker/scripts/preflight.sh" --root "$3" --mode dev --env-file "$3/preflight.env"' _ "$tmp/bin" "$tmp/docker.argv" "$repo" "$tmp/state"

grep -Fx 'compose version' "$tmp/docker.argv" >/dev/null
grep -F 'config --quiet' "$tmp/docker.argv" >/dev/null
if grep -Eq '(^| )(up|build|pull|down|run|create)( |$)' "$tmp/docker.argv"; then
    echo 'preflight invoked a mutating Docker action' >&2
    exit 1
fi

echo 'Preflight contract passed'
