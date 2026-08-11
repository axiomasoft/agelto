#!/bin/sh
# Reads file-backed Compose secrets exactly once, then removes each *_FILE variable.
set -eu

[ "${AGELTO_SECRETS_LOADED:-}" != '1' ] || exit 0

for secret_var in APP_KEY DB_PASSWORD REDIS_PASSWORD AWS_SECRET_ACCESS_KEY; do
    case "$secret_var" in
        APP_KEY) file_path="${APP_KEY_FILE:-}" ;;
        DB_PASSWORD) file_path="${DB_PASSWORD_FILE:-}" ;;
        REDIS_PASSWORD) file_path="${REDIS_PASSWORD_FILE:-}" ;;
        AWS_SECRET_ACCESS_KEY) file_path="${AWS_SECRET_ACCESS_KEY_FILE:-}" ;;
    esac

    [ -n "$file_path" ] || continue
    [ -f "$file_path" ] && [ ! -L "$file_path" ] || { echo "${secret_var}_FILE points to a missing secret file" >&2; exit 1; }
    value="$(cat "$file_path")"
    [ -n "$value" ] || { echo "${secret_var}_FILE points to an empty secret file" >&2; exit 1; }
    export "$secret_var=$value"
    unset "${secret_var}_FILE"
done

if [ "${AGELTO_ENTRYPOINT_PRELOAD:-}" = '1' ]; then
    export AGELTO_SECRETS_LOADED=1
    exec setpriv --reuid=33 --regid=33 --clear-groups --inh-caps=-all \
        /usr/local/bin/docker-php-serversideup-entrypoint "$@"
fi
