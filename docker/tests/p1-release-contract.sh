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

PATH="$temp_dir/bin:$PATH" DOCKER_LOG="$log_file" make -s -C "$root_dir" prod:deploy:code IMAGE_TAG=2026.08.10.1 COMPOSE_PROJECT_NAME=agelto_contract ENV_FILE=.env.example
grep -F 'pull app horizon scheduler' "$log_file" >/dev/null
grep -F 'up -d --no-build --remove-orphans app horizon scheduler' "$log_file" >/dev/null
! grep -Eq '(^| )(build|down|latest)( |$)' "$log_file"

bash "$root_dir/docker/tests/make-contract.sh"
bash "$root_dir/docker/tests/compose-contract.sh"
bash "$root_dir/docker/tests/prod-image-contract.sh" runtime-base
bash "$root_dir/docker/tests/vite-contract.sh"
echo 'P1 release contract passed'
