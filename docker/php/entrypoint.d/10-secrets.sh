#!/bin/sh
# Copies file-backed Compose secrets into a private runtime directory for PHP-FPM workers.
set -eu

[ "${AGELTO_SECRETS_LOADED:-}" != '1' ] || exit 0

runtime_dir=/run/agelto-secrets
install -d -m 0700 -o www-data -g www-data "$runtime_dir"

for secret_var in APP_KEY DB_PASSWORD REDIS_PASSWORD AWS_SECRET_ACCESS_KEY; do
    case "$secret_var" in
        APP_KEY) file_path="${APP_KEY_FILE:-}" ;;
        DB_PASSWORD) file_path="${DB_PASSWORD_FILE:-}" ;;
        REDIS_PASSWORD) file_path="${REDIS_PASSWORD_FILE:-}" ;;
        AWS_SECRET_ACCESS_KEY) file_path="${AWS_SECRET_ACCESS_KEY_FILE:-}" ;;
    esac

    [ -n "$file_path" ] || continue
    [ -f "$file_path" ] && [ ! -L "$file_path" ] || { echo "${secret_var}_FILE points to a missing secret file" >&2; exit 1; }
    [ -s "$file_path" ] || { echo "${secret_var}_FILE points to an empty secret file" >&2; exit 1; }
    runtime_file="$runtime_dir/$secret_var"
    cp "$file_path" "$runtime_file"
    chown www-data:www-data "$runtime_file"
    chmod 0600 "$runtime_file"
    export "${secret_var}_FILE=$runtime_file"
done

export AGELTO_SECRETS_LOADED=1

if [ "${AGELTO_ENTRYPOINT_PRELOAD:-}" = '1' ]; then
    exec /usr/local/bin/docker-php-serversideup-entrypoint "$@"
fi
