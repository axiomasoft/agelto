#!/usr/bin/env bash
set -euo pipefail
root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
mode=${1:-static}
cleanup_build() {
    rm -rf "$root_dir/public/build"
}
trap cleanup_build EXIT
grep -F '"packageManager": "pnpm@9.15.0"' "$root_dir/package.json" >/dev/null
grep -F 'strictPort: true' "$root_dir/vite.config.js" >/dev/null
grep -F 'vite_node_modules:/var/www/html/node_modules' "$root_dir/docker-compose.yml" >/dev/null
grep -F 'pnpm install --frozen-lockfile' "$root_dir/docker-compose.yml" >/dev/null
pnpm --dir "$root_dir" install --frozen-lockfile
pnpm --dir "$root_dir" build
test -f "$root_dir/public/build/manifest.json"

if [[ "$mode" == 'dynamic' ]]; then
    temp_dir=$(mktemp -d)
    project_name="agelto_p1_vite_${RANDOM}_$(date +%s)"
    vite_port=$((20000 + RANDOM % 20000))
    compose=(docker compose --project-directory "$root_dir" --project-name "$project_name" --env-file "$temp_dir/.env" -f "$root_dir/docker-compose.yml")

    cleanup() {
        "${compose[@]}" kill >/dev/null 2>&1 || true
        "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
        rm -f "$root_dir/public/hot"
        rm -rf "$temp_dir"
        cleanup_build
    }
    trap cleanup EXIT

    cat > "$temp_dir/.env" <<EOF
APP_NAME=agelto-vite-validation
APP_ENV=testing
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_DEBUG=false
APP_URL=http://localhost:${vite_port}
DB_DATABASE=agelto_vite_validation
DB_USERNAME=agelto
DB_PASSWORD=agelto-vite-validation-password
REDIS_PASSWORD=
MINIO_ROOT_USER=agelto
MINIO_ROOT_PASSWORD=agelto-vite-validation-password
APP_PORT=0
PGSQL_PORT=0
REDIS_PORT=0
MINIO_PORT=0
MINIO_CONSOLE_PORT=0
VITE_PORT=${vite_port}
EOF

    "${compose[@]}" up --detach --no-deps vite
    for _ in $(seq 1 90); do
        curl --fail --silent --show-error "http://127.0.0.1:${vite_port}/@vite/client" >/dev/null && break
        sleep 2
    done
    curl --fail --silent --show-error "http://127.0.0.1:${vite_port}/@vite/client" >/dev/null
    grep -Fx "http://localhost:${vite_port}" "$root_dir/public/hot" >/dev/null
fi

echo 'Vite contract passed'
