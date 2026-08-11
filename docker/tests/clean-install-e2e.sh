#!/usr/bin/env bash
set -euo pipefail

root_dir=$(git rev-parse --show-toplevel)
password_file=${E2E_ADMIN_PASSWORD_FILE:-}
admin_email=${E2E_ADMIN_EMAIL:-p4-acceptance@example.test}

[[ -z "$password_file" || -r "$password_file" ]] || { echo 'E2E admin password file is not readable' >&2; exit 2; }
! rg -n '^\s*container_name:' "$root_dir"/docker-compose*.yml >/dev/null

run_id="e2e-$(date +%s)-$RANDOM"
candidate=$(mktemp -d)
chmod 755 "$candidate"
project="agelto_${run_id//-/_}"
output_dir="$candidate/.e2e/$run_id"
e2e_env="$candidate/.env.e2e"

mapfile -t isolated_ports < <(python3 - <<'PY'
import random
import socket

ports = []
while len(ports) < 5:
    port = random.randint(20000, 50000)
    if port in ports:
        continue

    with socket.socket() as sock:
        try:
            sock.bind(('127.0.0.1', port))
        except OSError:
            continue

    ports.append(port)

print(*ports, sep='\n')
PY
)

(( ${#isolated_ports[@]} == 5 )) || { echo 'Could not allocate isolated E2E host ports' >&2; exit 2; }
app_port=${isolated_ports[0]}
pgsql_port=${isolated_ports[1]}
redis_port=${isolated_ports[2]}
minio_port=${isolated_ports[3]}
minio_console_port=${isolated_ports[4]}

cleanup() {
    local exit_status=$?

    if (( exit_status != 0 )); then
        if ! docker compose --project-directory "$candidate" --project-name "$project" --env-file "$e2e_env" -f "$candidate/docker-compose.yml" logs --tail 100 app >&2; then
            echo "Could not collect app logs for isolated Compose project $project" >&2
        fi
    fi

    if ! docker compose --project-directory "$candidate" --project-name "$project" --env-file "$e2e_env" -f "$candidate/docker-compose.yml" down --remove-orphans >/dev/null 2>&1; then
        echo "Could not fully tear down isolated Compose project $project" >&2
    fi

    if ! git -C "$root_dir" worktree remove --force "$candidate" >/dev/null 2>&1; then
        echo "Could not remove isolated candidate worktree $candidate" >&2
    fi

    return "$exit_status"
}
trap cleanup EXIT

git -C "$root_dir" worktree add --detach "$candidate" HEAD >/dev/null
if [[ -n "$password_file" ]]; then
    cp "$password_file" "$candidate/.e2e-admin-password"
else
    python3 - <<'PY' > "$candidate/.e2e-admin-password"
import secrets
print(secrets.token_urlsafe(48))
PY
fi
password_file="$candidate/.e2e-admin-password"
chmod 600 "$candidate/.e2e-admin-password"
python3 - "$candidate/.env.example" "$e2e_env" "$admin_email" "$app_port" "$pgsql_port" "$redis_port" "$minio_port" "$minio_console_port" <<'PY'
import re
import sys
from pathlib import Path

source = Path(sys.argv[1])
destination = Path(sys.argv[2])
admin_email = sys.argv[3]
ports = sys.argv[4:]
values = dict(zip(('APP_PORT', 'PGSQL_PORT', 'REDIS_PORT', 'MINIO_PORT', 'MINIO_CONSOLE_PORT'), ports, strict=True))
values['ADMIN_EMAIL'] = admin_email
content = source.read_text()
for name, port in values.items():
    content, count = re.subn(rf'^{name}=.*$', f'{name}={port}', content, flags=re.MULTILINE)
    if count > 1:
        raise SystemExit(f'Expected at most one {name} entry in E2E environment')
    if count == 0:
        content += f'\n{name}={port}\n'
destination.write_text(content)
PY
cp "$e2e_env" "$candidate/.env"

cd "$candidate"
candidate_sha=$(git rev-parse HEAD)
image_tag="p4-${candidate_sha:0:12}"
pnpm install --frozen-lockfile
pnpm documentation:build
pnpm exec playwright install chromium
make vendor:sync
composer install --no-scripts --no-interaction --no-progress
COMPOSE_PROJECT_NAME="$project" IMAGE_TAG="$image_tag" make bootstrap MODE=dev ENV_FILE=.env.e2e ADMIN_PASSWORD_FILE=.e2e-admin-password

readiness_green=false
for _ in $(seq 1 45); do
    if COMPOSE_PROJECT_NAME="$project" make readiness:check ENV_FILE=.env.e2e; then
        readiness_green=true
        break
    fi

    sleep 3
done

if ! "$readiness_green"; then
    echo 'Application readiness did not become green within 135 seconds' >&2
    exit 1
fi

E2E_BASE_URL="http://127.0.0.1:$app_port" \
E2E_ADMIN_EMAIL="$admin_email" \
E2E_ADMIN_PASSWORD_FILE="$candidate/.e2e-admin-password" \
E2E_AUTH_FILE="$output_dir/admin.json" \
E2E_OUTPUT_DIR="$output_dir" \
E2E_COMPOSE_PROJECT="$project" \
E2E_COMPOSE_ENV_FILE="$e2e_env" \
pnpm exec playwright test

image_id=$(docker image inspect --format '{{.Id}}' "agelto/app:$image_tag")
[[ "$image_id" == sha256:* ]] || { echo 'Application image digest is invalid' >&2; exit 1; }
printf 'P4_CANDIDATE_SHA=%s\nP4_APPLICATION_IMAGE=%s\n' "$candidate_sha" "$image_id"
