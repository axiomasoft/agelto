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
grep -Fq 'cp "$source_acl" "$runtime_acl"' "$root/docker/redis/entrypoint.sh"
grep -Fq 'chown redis:redis "$runtime_dir" "$runtime_acl"' "$root/docker/redis/entrypoint.sh"
grep -Fq 'exec /usr/local/bin/docker-entrypoint.sh redis-server --aclfile "$runtime_acl" "$@"' "$root/docker/redis/entrypoint.sh"
grep -Fq './docker/redis/entrypoint.sh:/usr/local/bin/agelto-redis-entrypoint:ro' "$root/docker-compose.yml"
grep -Fq './docker/redis/entrypoint.sh:/usr/local/bin/agelto-redis-entrypoint:ro' "$root/docker-compose.prod.yml"
grep -Fq 'CMD ["/init"]' "$root/docker/php/Dockerfile"
grep -Fq 'docker-php-serversideup-entrypoint.real' "$root/docker/php/Dockerfile"
grep -Fq 'exec /usr/local/bin/docker-php-serversideup-entrypoint "$@"' "$root/docker/php/entrypoint.d/10-secrets.sh"
grep -Fq 'exec setpriv --reuid=www-data --regid=www-data --init-groups /usr/local/bin/docker-php-serversideup-entrypoint "$@"' "$root/docker/php/entrypoint.d/10-secrets.sh"
! grep -Fq 'exec setpriv --reuid=33' "$root/docker/php/entrypoint.d/10-secrets.sh"
grep -Fq 'runtime_dir=/run/agelto-secrets' "$root/docker/php/entrypoint.d/10-secrets.sh"
grep -Fq 'export "${secret_var}_FILE=$runtime_file"' "$root/docker/php/entrypoint.d/10-secrets.sh"
grep -Fq 'export AGELTO_SECRETS_LOADED=1' "$root/docker/php/entrypoint.d/10-secrets.sh"
expect_failure env -i PATH="$PATH" APP_ENV=production APP_DEBUG=true sh "$root/docker/php/entrypoint.d/10-secrets.sh"
expect_failure env -i PATH="$PATH" APP_ENV=production APP_DEBUG=false sh "$root/docker/php/entrypoint.d/10-secrets.sh"
env -i PATH="$PATH" AGELTO_SECRETS_LOADED=1 sh "$root/docker/php/entrypoint.d/10-secrets.sh"
grep -Fq 'public static function resolve(string $name, mixed $default = null): mixed' "$root/app/Support/SecretValue.php"
grep -Fq '[SecretValue::class, '\''resolve'\'']' "$root/config/app.php"
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
