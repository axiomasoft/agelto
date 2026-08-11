#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

repo="$temp_dir/repo"
mkdir -p "$repo/docker/scripts" "$repo/local/secrets" "$temp_dir/bin"
cp "$root_dir/docker/scripts/bootstrap.sh" "$repo/docker/scripts/bootstrap.sh"

cat > "$temp_dir/bin/make" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${MAKE_LOG:?}"
target="${*: -1}"
for argument in "$@"; do
    case "$argument" in
        vendor:sync) target='vendor:sync' ;;
        secrets:init) target='secrets:init' ;;
        infra:preflight) target='infra:preflight' ;;
        image:build) target='image:build' ;;
        dev:up) target='dev:up' ;;
        dev:storage-init) target='dev:storage-init' ;;
        dev:composer) target='dev:composer' ;;
        assets:repair) target='assets:repair' ;;
        dev:migrate) target='dev:migrate' ;;
        dev:seed-base) target='dev:seed-base' ;;
        dev:admin-create) target='dev:admin-create' ;;
        dev:health) target='dev:health' ;;
        prod:config) target='prod:config' ;;
    esac
done
if [[ "${FAIL_TARGET:-}" == "$target" ]]; then
    exit 51
fi
case "$target" in
    vendor:sync)
        test -e "${FAKE_REPO:?}/vendor.identity" || printf 'vendor-pin\n' > "${FAKE_REPO}/vendor.identity"
        ;;
    secrets:init)
        mkdir -p "${FAKE_REPO}/local/secrets"
        test -e "${FAKE_REPO}/local/secrets/app_key" || printf 'super-secret\n' > "${FAKE_REPO}/local/secrets/app_key"
        ;;
    dev:up)
        test -e "${FAKE_REPO}/volume.identity" || printf 'volume-identity\n' > "${FAKE_REPO}/volume.identity"
        ;;
esac
EOF
chmod +x "$temp_dir/bin/make"

cat > "$temp_dir/bin/git" <<'EOF'
#!/usr/bin/env bash
test "$1" = -C
test "$3" = rev-parse
printf '%040d\n' 0
EOF
chmod +x "$temp_dir/bin/git"

run_bootstrap() {
    PATH="$temp_dir/bin:$PATH" MAKE_LOG="$temp_dir/make.log" FAKE_REPO="$repo" \
        bash "$repo/docker/scripts/bootstrap.sh" --root "$repo" "$@"
}

run_bootstrap --mode dev --env-file .env.example > "$temp_dir/dev.out"

mapfile -t stages < <(sed -E 's/.* (vendor:sync|secrets:init|infra:preflight|image:build|dev:up|dev:storage-init|dev:composer|assets:repair|dev:migrate|dev:seed-base|dev:health).*/\1/' "$temp_dir/make.log")
[[ "${stages[*]}" == 'vendor:sync secrets:init infra:preflight image:build dev:up dev:storage-init dev:composer assets:repair dev:migrate dev:seed-base dev:health' ]]
grep -F 'Bootstrap completed: dev stack is healthy.' "$temp_dir/dev.out" >/dev/null
! grep -F 'super-secret' "$temp_dir/dev.out"
python3 - "$repo/local/secrets/.bootstrap-progress" <<'PY'
import json
import sys

progress = json.load(open(sys.argv[1], encoding='utf-8'))
assert progress['stage'] == 'dev-health'
assert progress['status'] == 'passed'
assert progress['source_sha'] == '0' * 40
PY

secret_before=$(sha256sum "$repo/local/secrets/app_key" "$repo/vendor.identity" "$repo/volume.identity")
run_bootstrap --mode dev --env-file .env.example >/dev/null
secret_after=$(sha256sum "$repo/local/secrets/app_key" "$repo/vendor.identity" "$repo/volume.identity")
[[ "$secret_before" == "$secret_after" ]]

: > "$temp_dir/make.log"
run_bootstrap --mode prod-build --env-file .env.production --image-tag immutable-test --project-name agelto_contract > "$temp_dir/prod.out"
grep -F 'MODE=prod-build' "$temp_dir/make.log" >/dev/null
grep -F 'image:build' "$temp_dir/make.log" >/dev/null
grep -F 'prod:config' "$temp_dir/make.log" >/dev/null
if grep -Eq 'dev:|prod:deploy|down|migrate|reset|clean|--volumes' "$temp_dir/make.log"; then
    echo 'bootstrap executed a forbidden production action' >&2
    exit 1
fi
grep -F 'immutable production image was built' "$temp_dir/prod.out" >/dev/null

set +e
FAIL_TARGET=infra:preflight PATH="$temp_dir/bin:$PATH" MAKE_LOG="$temp_dir/make.log" FAKE_REPO="$repo" \
    bash "$repo/docker/scripts/bootstrap.sh" --root "$repo" --mode dev --env-file .env.example > "$temp_dir/failure.out" 2>&1
status=$?
set -e
[[ "$status" -eq 51 ]]
grep -Fx 'Bootstrap failed at stage preflight. Resume: make bootstrap MODE=dev ENV_FILE=.env.example IMAGE_TAG=bootstrap-000000000000 COMPOSE_PROJECT_NAME=agelto_bootstrap' "$temp_dir/failure.out" >/dev/null
python3 - "$repo/local/secrets/.bootstrap-progress" <<'PY'
import json
import sys

progress = json.load(open(sys.argv[1], encoding='utf-8'))
assert progress['stage'] == 'preflight'
assert progress['status'] == 'failed'
PY

if rg -n 'down --volumes|migrate:fresh|(^|[^[:alpha:]])(reset|clean)([^[:alpha:]]|$)|prod:deploy' "$root_dir/docker/scripts/bootstrap.sh"; then
    echo 'bootstrap script contains a destructive or deployment command' >&2
    exit 1
fi

echo 'Bootstrap contract passed'
