#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

mkdir -p "$temp_dir/bin"
log_file="$temp_dir/docker.argv"
preflight_env="$temp_dir/preflight.env"
cp "$root_dir/.env.example" "$preflight_env"
sed -i \
    -e 's/^APP_PORT=.*/APP_PORT=38190/' \
    -e 's/^PGSQL_PORT=.*/PGSQL_PORT=38191/' \
    -e 's/^REDIS_PORT=.*/REDIS_PORT=38192/' \
    -e 's/^MINIO_PORT=.*/MINIO_PORT=38193/' \
    -e 's/^MINIO_CONSOLE_PORT=.*/MINIO_CONSOLE_PORT=38194/' \
    "$preflight_env"

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

run_make infra:preflight MODE=dev ENV_FILE="$preflight_env"
run_make agent:artisan ARGS='about'
run_make prod:config ENV_FILE=.env.example IMAGE_TAG=contract-tag COMPOSE_PROJECT_NAME=agelto_contract

grep -Fx 'compose version' "$log_file" >/dev/null
grep -F 'compose --project-directory ' "$log_file" | grep -F -- '-f ' | grep -F 'docker-compose.yml config' >/dev/null
grep -E 'exec -T ([-]e [^ ]+ )*app php artisan about$' "$log_file" >/dev/null
grep -F 'docker-compose.prod.yml config' "$log_file" >/dev/null

if grep -Eq '(^| )(up|build|pull|down)( |$)' "$log_file"; then
    echo 'Contract failed: a non-mutating command invoked a mutating Docker action' >&2
    exit 1
fi

: > "$log_file"
for legacy_target in up down artisan; do
    target="legacy:${legacy_target}"
    if run_make "$target"; then
        echo "Contract failed: expired target $target remains public" >&2
        exit 1
    fi
done

if run_make help | grep -F 'legacy:' >/dev/null; then
    echo 'Contract failed: expired targets remain in make help' >&2
    exit 1
fi

: > "$log_file"
for target in prod:deploy:code prod:deploy:php-deps prod:deploy:frontend prod:deploy:runtime prod:image:promote prod:deploy:image; do
    run_make "$target" ENV_FILE=.env.example IMAGE_TAG=contract-tag COMPOSE_PROJECT_NAME=agelto_contract
done
grep -F 'pull app horizon scheduler process-relay process-reconciler process-feeder process-projector' "$log_file" >/dev/null
grep -Fx 'pull agelto/app:contract-tag' "$log_file" >/dev/null
grep -F 'up -d --no-build --remove-orphans app horizon scheduler process-relay process-reconciler process-feeder process-projector' "$log_file" >/dev/null
if grep -Eq '(^| )(build|down|latest)( |$)' "$log_file"; then
    echo 'Contract failed: a production release target built, tore down, or used latest' >&2
    exit 1
fi

: > "$log_file"
for target in prod:infra:up prod:app:up prod:storage:init prod:workers:up prod:readiness; do
    run_make "$target" ENV_FILE=.env.example IMAGE_TAG=contract-tag COMPOSE_PROJECT_NAME=agelto_contract
done
run_make prod:admin:create ENV_FILE=.env.example IMAGE_TAG=contract-tag COMPOSE_PROJECT_NAME=agelto_contract PASSWORD_FILE="$temp_dir/initial-admin-password"
grep -F 'build postgres' "$log_file" >/dev/null
grep -F 'up -d --no-build --wait postgres redis minio' "$log_file" >/dev/null
grep -F 'up -d --no-build --wait app' "$log_file" >/dev/null
grep -F 'exec -T app sh -ec mc alias set agelto-bootstrap' "$log_file" >/dev/null
grep -F 'AWS_SECRET_ACCESS_KEY_FILE' "$log_file" >/dev/null
grep -F 'up -d --no-build --wait horizon scheduler process-relay process-reconciler process-feeder process-projector' "$log_file" >/dev/null
grep -F '"${compose[@]}" up -d --wait app horizon scheduler process-relay process-reconciler process-feeder process-projector' "$root_dir/docker/tests/clean-install-e2e.sh" >/dev/null
grep -F '/run/secrets/initial_admin_password:ro' "$log_file" >/dev/null
grep -F 'exec -T app php artisan app:readiness --format=agent' "$log_file" >/dev/null
