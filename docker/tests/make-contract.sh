#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

mkdir -p "$temp_dir/bin"
log_file="$temp_dir/docker.argv"

cat > "$temp_dir/bin/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${DOCKER_LOG:?}"
if [[ "$1 $2" == 'compose version' ]]; then
    exit 0
fi
exit 0
EOF
chmod +x "$temp_dir/bin/docker"

run_make() {
    PATH="$temp_dir/bin:$PATH" DOCKER_LOG="$log_file" make -s -C "$root_dir" "$@"
}

run_make infra:preflight MODE=dev ENV_FILE=.env.example
run_make agent:artisan ARGS='about'
run_make prod:config ENV_FILE=.env.example IMAGE_TAG=contract-tag COMPOSE_PROJECT_NAME=agelto_contract

grep -Fx 'compose version' "$log_file" >/dev/null
grep -F 'compose --project-directory ' "$log_file" | grep -F -- '-f ' | grep -F 'docker-compose.yml config' >/dev/null
grep -F 'exec -T app php artisan about' "$log_file" >/dev/null
grep -F 'docker-compose.prod.yml config' "$log_file" >/dev/null

if grep -Eq '(^| )(up|build|pull|down)( |$)' "$log_file"; then
    echo 'Contract failed: a non-mutating command invoked a mutating Docker action' >&2
    exit 1
fi

: > "$log_file"
run_make legacy:up
grep -F 'docker-compose.yml up -d' "$log_file" >/dev/null
