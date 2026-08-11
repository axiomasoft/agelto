#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd -P)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
repo="$tmp/repo"
mkdir -p "$repo/docker/scripts" "$repo/local/secrets"
cp "$root/docker/secrets.contract" "$repo/docker/secrets.contract"
cp "$root/docker/scripts/secrets-init.sh" "$repo/docker/scripts/secrets-init.sh"
cp "$root/local/secrets/.gitignore" "$repo/local/secrets/.gitignore"

bash -n "$root/docker/scripts/secrets-init.sh"
bash -n "$root/docker/php/entrypoint.d/10-secrets.sh"

expect_failure() {
    set +e
    "$@" >/dev/null 2>&1
    status=$?
    set -e

    test "$status" -ne 0
}

rm -rf "$repo/local/secrets"
ln -s "$tmp" "$repo/local/secrets"
expect_failure bash "$repo/docker/scripts/secrets-init.sh" --root "$repo"
rm "$repo/local/secrets"
mkdir -p "$repo/local/secrets"

printf 'app_key\napp_key\n' > "$repo/docker/secrets.contract"
expect_failure bash "$repo/docker/scripts/secrets-init.sh" --root "$repo"
cp "$root/docker/secrets.contract" "$repo/docker/secrets.contract"

bash "$repo/docker/scripts/secrets-init.sh" --root "$repo" >/dev/null
first="$(sha256sum "$repo/local/secrets"/{app_key,db_password,redis_password,redis_acl,minio_root_password,admin_initial_password})"
bash "$repo/docker/scripts/secrets-init.sh" --root "$repo" >/dev/null
test "$first" = "$(sha256sum "$repo/local/secrets"/{app_key,db_password,redis_password,redis_acl,minio_root_password,admin_initial_password})"
test "$(stat -c %a "$repo/local/secrets/app_key")" = 600
rg -F 'cp "$source_acl" "$runtime_acl"' "$root/docker/redis/entrypoint.sh" >/dev/null
rg -F 'chown redis:redis "$runtime_dir" "$runtime_acl"' "$root/docker/redis/entrypoint.sh" >/dev/null
rg -F 'exec /usr/local/bin/docker-entrypoint.sh redis-server --aclfile "$runtime_acl" "$@"' "$root/docker/redis/entrypoint.sh" >/dev/null
rg -F './docker/redis/entrypoint.sh:/usr/local/bin/agelto-redis-entrypoint:ro' "$root/docker-compose.yml" "$root/docker-compose.prod.yml" >/dev/null
rg -F 'CMD ["/init"]' "$root/docker/php/Dockerfile" >/dev/null
rg -F 'exec /usr/local/bin/docker-php-serversideup-entrypoint "$@"' "$root/docker/php/entrypoint.d/10-secrets.sh" >/dev/null
! rg -F 'exec setpriv --reuid=33' "$root/docker/php/entrypoint.d/10-secrets.sh"
rg -F 'runtime_dir=/run/agelto-secrets' "$root/docker/php/entrypoint.d/10-secrets.sh" >/dev/null
rg -F 'export "${secret_var}_FILE=$runtime_file"' "$root/docker/php/entrypoint.d/10-secrets.sh" >/dev/null
rg -F 'return static function (string $name, mixed $default = null): mixed {' "$root/config/secrets.php" >/dev/null
bash "$repo/docker/scripts/secrets-init.sh" --root "$repo" --rotate db_password >/dev/null
test -f "$repo/local/secrets/db_password.next"
rm "$repo/local/secrets/db_password.next"
rm "$repo/local/secrets/db_password"
ln -s "$tmp/not-a-secret" "$repo/local/secrets/db_password"
expect_failure bash "$repo/docker/scripts/secrets-init.sh" --root "$repo"
! grep -R --exclude='.gitignore' -E 'change-me|MINIO_ROOT_PASSWORD=' "$root/.env.docker.example" "$root/.env.docker.production.example"
grep -q 'POSTGRES_PASSWORD_FILE: /run/secrets/db_password' "$root/docker-compose.yml"
grep -q 'MINIO_ROOT_PASSWORD_FILE: /run/secrets/minio_root_password' "$root/docker-compose.prod.yml"
grep -q 'file: ./local/secrets/app_key' "$root/docker-compose.yml"
grep -q 'file: ./local/secrets/app_key' "$root/docker-compose.prod.yml"
grep -q '/local/secrets/\*' "$root/.gitignore"
grep -q 'local/secrets/' "$root/.dockerignore"
git -C "$root" check-ignore local/secrets/not-a-secret >/dev/null
git -C "$root" check-ignore local/secrets/.gitignore >/dev/null && exit 1 || true
echo 'Secrets contract passed'
