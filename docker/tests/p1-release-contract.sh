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
echo 'P1 release contract passed'
