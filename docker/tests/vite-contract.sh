#!/usr/bin/env bash
set -euo pipefail
root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
grep -F '"packageManager": "pnpm@9.15.0"' "$root_dir/package.json" >/dev/null
grep -F 'strictPort: true' "$root_dir/vite.config.js" >/dev/null
grep -F 'vite_node_modules:/var/www/html/node_modules' "$root_dir/docker-compose.yml" >/dev/null
grep -F 'pnpm install --frozen-lockfile' "$root_dir/docker-compose.yml" >/dev/null
pnpm --dir "$root_dir" install --frozen-lockfile
pnpm --dir "$root_dir" build
test -f "$root_dir/public/build/manifest.json"
echo 'Vite contract passed'
