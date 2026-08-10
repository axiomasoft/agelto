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
exit 0
EOF
chmod +x "$temp_dir/bin/docker"

run_release_target() {
    : > "$log_file"
    PATH="$temp_dir/bin:$PATH" DOCKER_LOG="$log_file" make -s -C "$root_dir" "$1" IMAGE_TAG=2026.08.10.1 COMPOSE_PROJECT_NAME=agelto_contract ENV_FILE=.env.example
}

for target in prod:deploy:code prod:deploy:php-deps prod:deploy:frontend prod:deploy:runtime; do
    run_release_target "$target"
    grep -F 'compose --project-directory ' "$log_file" | grep -F -- '--project-name agelto_contract' | grep -F 'docker-compose.prod.yml config' >/dev/null
    grep -F 'pull app horizon scheduler' "$log_file" >/dev/null
    grep -F 'up -d --no-build --remove-orphans app horizon scheduler' "$log_file" >/dev/null
    ! grep -Eq '(^| )(build|down|latest)( |$)' "$log_file"
done

run_release_target prod:image:promote
grep -Fx 'pull agelto/app:2026.08.10.1' "$log_file" >/dev/null
! grep -Eq '(^| )(build|down|latest)( |$)' "$log_file"

run_release_target prod:deploy:image
grep -Fx 'pull agelto/app:2026.08.10.1' "$log_file" >/dev/null
grep -F 'pull app horizon scheduler' "$log_file" >/dev/null
grep -F 'up -d --no-build --remove-orphans app horizon scheduler' "$log_file" >/dev/null
! grep -Eq '(^| )(build|down|latest)( |$)' "$log_file"

bash "$root_dir/docker/tests/make-contract.sh"
bash "$root_dir/docker/tests/compose-contract.sh"
bash "$root_dir/docker/tests/dev-code-sync.sh"
bash "$root_dir/docker/tests/prod-image-contract.sh" application
bash "$root_dir/docker/tests/vite-contract.sh" dynamic

# The AI guideline is the source; its release boundary must be reflected in the Claude hub and
# operator docs, rather than relying on a green Docker-only test surface.
release_boundary='production consumes a promoted immutable `IMAGE_TAG` without source, `vendor`, or frontend bind mounts and never builds on the host'
grep -F "$release_boundary" "$root_dir/.ai/guidelines/architecture.md" >/dev/null
grep -F "$release_boundary" "$root_dir/CLAUDE.md" >/dev/null
grep -F 'make prod:deploy:{code,php-deps,frontend,runtime}' "$root_dir/docs/03_Dev/commands.md" >/dev/null
for target in prod:deploy:code prod:deploy:php-deps prod:deploy:frontend prod:deploy:runtime prod:image:promote prod:deploy:image; do
    grep -F "$target" "$root_dir/docs/03_Dev/process/deploy.md" >/dev/null
done
! rg -n '\byarn\b' \
    "$root_dir/.ai/guidelines/architecture.md" \
    "$root_dir/CLAUDE.md" \
    "$root_dir/docs/03_Dev/getting-started.md" \
    "$root_dir/docs/03_Dev/commands.md" \
    "$root_dir/docs/03_Dev/docker/index.md" \
    "$root_dir/docs/03_Dev/docker/development.md" \
    "$root_dir/docs/03_Dev/docker/production.md" \
    "$root_dir/docs/03_Dev/docker/troubleshooting.md" \
    "$root_dir/docs/03_Dev/process/deploy.md"

task_root=${TASK_ROOT:-}
if [[ -z "$task_root" ]]; then
    task_root=$(codex plugin marketplace list --json | python3 -c '
import json
import pathlib
import sys

roots = [
    item["root"]
    for item in json.load(sys.stdin).get("marketplaces", [])
    if item.get("name") == "swissknifeman"
    and (pathlib.Path(item["root"]) / "packages/task/lib/task").is_dir()
]
if len(roots) != 1:
    raise SystemExit("task runtime unavailable: expected one swissknifeman marketplace")
print(roots[0])
')
fi

git -C "$root_dir" diff --check
python3 "$task_root/packages/task/scripts/plan-lint.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS" --baseline HEAD
python3 "$task_root/packages/task/scripts/plan-views.py" state --all --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS" --check
python3 "$task_root/packages/task/scripts/plan-views.py" bundle --all --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS" --check
python3 "$task_root/packages/task/scripts/plan-views.py" decisions --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS" --check
python3 "$task_root/packages/task/scripts/plan-views.py" status --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS" --check
(
    cd "$root_dir"
    python3 plans/verify-citations.py plans/2026.08.09-RELEASE-READINESS --strict
)

echo 'P1 release contract passed'
