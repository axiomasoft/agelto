#!/usr/bin/env bash
set -euo pipefail

root_dir=$(git rev-parse --show-toplevel)
password_file=${E2E_ADMIN_PASSWORD_FILE:?E2E_ADMIN_PASSWORD_FILE is required}
admin_email=${E2E_ADMIN_EMAIL:?E2E_ADMIN_EMAIL is required}

[[ -r "$password_file" ]] || { echo 'E2E admin password file is not readable' >&2; exit 2; }
[[ -z "$(git status --porcelain)" ]] || { echo 'Clean-install E2E refuses a dirty source tree' >&2; exit 2; }
! rg -n '^\s*container_name:' "$root_dir"/docker-compose*.yml >/dev/null

run_id="e2e-$(date +%s)-$RANDOM"
candidate=$(mktemp -d)
project="agelto_${run_id//-/_}"
app_port=$((20000 + RANDOM % 10000))
output_dir="$candidate/.e2e/$run_id"

cleanup() {
    if ! docker compose --project-directory "$candidate" --project-name "$project" --env-file "$candidate/.env.example" -f "$candidate/docker-compose.yml" down --remove-orphans >/dev/null 2>&1; then
        echo "Could not fully tear down isolated Compose project $project" >&2
    fi

    if ! git -C "$root_dir" worktree remove --force "$candidate" >/dev/null 2>&1; then
        echo "Could not remove isolated candidate worktree $candidate" >&2
    fi
}
trap cleanup EXIT

git -C "$root_dir" worktree add --detach "$candidate" HEAD >/dev/null
cp "$password_file" "$candidate/.e2e-admin-password"
chmod 600 "$candidate/.e2e-admin-password"

cd "$candidate"
pnpm install --frozen-lockfile
pnpm exec playwright install chromium
APP_PORT="$app_port" COMPOSE_PROJECT_NAME="$project" make bootstrap MODE=dev ENV_FILE=.env.example ADMIN_PASSWORD_FILE=.e2e-admin-password
APP_PORT="$app_port" COMPOSE_PROJECT_NAME="$project" make readiness:check ENV_FILE=.env.example

E2E_BASE_URL="http://127.0.0.1:$app_port" \
E2E_ADMIN_EMAIL="$admin_email" \
E2E_ADMIN_PASSWORD_FILE="$candidate/.e2e-admin-password" \
E2E_AUTH_FILE="$output_dir/admin.json" \
E2E_OUTPUT_DIR="$output_dir" \
pnpm exec playwright test
