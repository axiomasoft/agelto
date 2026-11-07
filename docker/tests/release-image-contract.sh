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
if [[ "${1:-} ${2:-}" == 'image inspect' ]]; then
    if [[ "$*" == *'agelto/doctor:'* && -v FAKE_DOCTOR_IMAGE_LABELS ]]; then
        printf '%s\n' "${FAKE_DOCTOR_IMAGE_LABELS:-:}"
    elif [[ "$*" == *'agelto/postgres:'* ]]; then
        if [[ -v FAKE_POSTGRES_IMAGE_REVISION ]]; then
            printf '%s\n' "${FAKE_POSTGRES_IMAGE_REVISION}"
        elif [[ -v FAKE_IMAGE_LABELS ]]; then
            printf '%s\n' "${FAKE_IMAGE_LABELS%%:*}"
        else
            printf '%s\n' 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'
        fi
    elif [[ -v FAKE_IMAGE_LABELS ]]; then
        printf '%s\n' "${FAKE_IMAGE_LABELS:-:}"
    else
        printf '%s\n' "${FAKE_IMAGE_LABELS:-deadbeefdeadbeefdeadbeefdeadbeefdeadbeef:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef}"
    fi
fi
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
    grep -F 'pull app horizon scheduler process-relay process-reconciler process-feeder process-projector' "$log_file" >/dev/null
    grep -F 'up -d --no-build --remove-orphans app horizon scheduler process-relay process-reconciler process-feeder process-projector' "$log_file" >/dev/null
    grep -F 'exec -T app php artisan app:readiness --format=agent' "$log_file" >/dev/null
    ! grep -Eq '(^| )(build|down|latest)( |$)' "$log_file"
done

run_release_target prod:image:promote
grep -Fx 'pull agelto/app:2026.08.10.1' "$log_file" >/dev/null
grep -Fx 'pull agelto/doctor:2026.08.10.1' "$log_file" >/dev/null
grep -Fx 'pull agelto/postgres:2026.08.10.1' "$log_file" >/dev/null
grep -F 'image inspect --format {{index .Config.Labels "org.opencontainers.image.revision"}}:{{index .Config.Labels "io.agelto.vendor-lock-sha"}} agelto/app:2026.08.10.1' "$log_file" >/dev/null
grep -F 'image inspect --format {{index .Config.Labels "org.opencontainers.image.revision"}}:{{index .Config.Labels "io.agelto.vendor-lock-sha"}} agelto/doctor:2026.08.10.1' "$log_file" >/dev/null
grep -F 'image inspect --format {{index .Config.Labels "org.opencontainers.image.revision"}} agelto/postgres:2026.08.10.1' "$log_file" >/dev/null
! grep -Eq '(^| )(build|down|latest)( |$)' "$log_file"

! FAKE_IMAGE_LABELS='' run_release_target prod:image:promote >/dev/null 2>&1
! FAKE_IMAGE_LABELS='deadbeef:' run_release_target prod:image:promote >/dev/null 2>&1
! FAKE_DOCTOR_IMAGE_LABELS='1111111111111111111111111111111111111111:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef' run_release_target prod:image:promote >/dev/null 2>&1
! FAKE_POSTGRES_IMAGE_REVISION='1111111111111111111111111111111111111111' run_release_target prod:image:promote >/dev/null 2>&1
! EXPECTED_SOURCE_SHA='1111111111111111111111111111111111111111' run_release_target prod:image:promote >/dev/null 2>&1

run_release_target prod:deploy:image
grep -Fx 'pull agelto/app:2026.08.10.1' "$log_file" >/dev/null
grep -Fx 'pull agelto/doctor:2026.08.10.1' "$log_file" >/dev/null
grep -Fx 'pull agelto/postgres:2026.08.10.1' "$log_file" >/dev/null
grep -F 'pull app horizon scheduler process-relay process-reconciler process-feeder process-projector' "$log_file" >/dev/null
grep -F 'up -d --no-build --remove-orphans app horizon scheduler process-relay process-reconciler process-feeder process-projector' "$log_file" >/dev/null
grep -F 'exec -T app php artisan app:readiness --format=agent' "$log_file" >/dev/null
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
grep -F 'make prod:deploy:{code,php-deps,frontend,runtime}' "$root_dir/docs/dev/commands.md" >/dev/null
for target in prod:deploy:code prod:deploy:php-deps prod:deploy:frontend prod:deploy:runtime prod:image:promote prod:deploy:image; do
    grep -F "$target" "$root_dir/docs/dev/process/deploy.md" >/dev/null
done
! grep -En '\byarn\b' \
    "$root_dir/.ai/guidelines/architecture.md" \
    "$root_dir/CLAUDE.md" \
    "$root_dir/docs/dev/getting-started.md" \
    "$root_dir/docs/dev/commands.md" \
    "$root_dir/docs/dev/docker/index.md" \
    "$root_dir/docs/dev/docker/development.md" \
    "$root_dir/docs/dev/docker/production.md" \
    "$root_dir/docs/dev/docker/troubleshooting.md" \
    "$root_dir/docs/dev/process/deploy.md"

git -C "$root_dir" diff --check

echo 'release contract passed'
