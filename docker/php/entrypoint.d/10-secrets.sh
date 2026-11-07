#!/bin/sh
# Copies file-backed Compose secrets into a private runtime directory for PHP-FPM workers and CLI processes.
set -eu

if [ "${AGELTO_SECRETS_LOADED:-0}" = "1" ]; then
    exit 0
fi

if [ "${APP_ENV:-}" = "production" ]; then
    if [ "${APP_DEBUG:-false}" = "true" ] || [ "${APP_DEBUG:-0}" = "1" ]; then
        echo "APP_DEBUG must be false in production" >&2
        exit 1
    fi
    for required_secret_file in APP_KEY_FILE DB_PASSWORD_FILE REDIS_PASSWORD_FILE AWS_SECRET_ACCESS_KEY_FILE; do
        eval "secret_path=\${$required_secret_file:-}"
        if [ -z "$secret_path" ]; then
            echo "$required_secret_file is required in production" >&2
            exit 1
        fi
    done
    if [ "${AWS_SECRET_ACCESS_KEY_FILE:-}" = "/run/secrets/minio_root_password" ]; then
        echo "minio_root_password is forbidden for application runtime; use minio_app_password" >&2
        exit 1
    fi
fi

runtime_dir=/run/agelto-secrets
install -d -m 0700 -o www-data -g www-data "$runtime_dir"

for secret_var in APP_KEY DB_PASSWORD REDIS_PASSWORD REDIS_CACHE_PASSWORD REDIS_SESSION_PASSWORD AWS_SECRET_ACCESS_KEY MINIO_APP_PASSWORD; do
    case "$secret_var" in
        APP_KEY) file_path="${APP_KEY_FILE:-}" ;;
        DB_PASSWORD) file_path="${DB_PASSWORD_FILE:-}" ;;
        REDIS_PASSWORD) file_path="${REDIS_PASSWORD_FILE:-}" ;;
        REDIS_CACHE_PASSWORD) file_path="${REDIS_CACHE_PASSWORD_FILE:-}" ;;
        REDIS_SESSION_PASSWORD) file_path="${REDIS_SESSION_PASSWORD_FILE:-}" ;;
        AWS_SECRET_ACCESS_KEY) file_path="${AWS_SECRET_ACCESS_KEY_FILE:-}" ;;
        MINIO_APP_PASSWORD) file_path="${MINIO_APP_PASSWORD_FILE:-}" ;;
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

if [ "${AGELTO_ENTRYPOINT_PRELOAD:-0}" = "1" ]; then
    if [ "$(id -u)" = "0" ]; then
        for writable_dir in /var/www/html/storage /var/www/html/bootstrap/cache; do
            if [ -d "$writable_dir" ]; then
                chown -R www-data:www-data "$writable_dir" 2>/dev/null || true
            fi
        done
        for extra_secret in initial_admin_password e2e_password; do
            if [ -f "/run/secrets/$extra_secret" ] && [ ! -L "/run/secrets/$extra_secret" ]; then
                cp "/run/secrets/$extra_secret" "$runtime_dir/$extra_secret"
                chown www-data:www-data "$runtime_dir/$extra_secret"
                chmod 0600 "$runtime_dir/$extra_secret"
            fi
        done
        if [ -f "$runtime_dir/initial_admin_password" ] || [ -f "$runtime_dir/e2e_password" ]; then
            first_arg=1
            for arg in "$@"; do
                if [ "$first_arg" = "1" ]; then
                    set --
                    first_arg=0
                fi
                case "$arg" in
                    */run/secrets/initial_admin_password*|*/run/secrets/e2e_password*)
                        arg=$(printf '%s' "$arg" | sed "s#/run/secrets/initial_admin_password#$runtime_dir/initial_admin_password#g; s#/run/secrets/e2e_password#$runtime_dir/e2e_password#g")
                        ;;
                esac
                set -- "$@" "$arg"
            done
        fi
    fi
    if [ "$#" -gt 0 ] && [ "$1" != "/init" ] && [ "$1" != "php-fpm" ] && [ "$(id -u)" = "0" ]; then
        export HOME=/var/www
        exec setpriv --reuid=www-data --regid=www-data --init-groups /usr/local/bin/docker-php-serversideup-entrypoint "$@"
    else
        exec /usr/local/bin/docker-php-serversideup-entrypoint "$@"
    fi
fi
