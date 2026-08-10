#!/bin/sh
# Reads file-backed Compose secrets exactly once, then removes each *_FILE variable.
set -eu

for secret_var in APP_KEY DB_PASSWORD REDIS_PASSWORD AWS_SECRET_ACCESS_KEY; do
    file_var="${secret_var}_FILE"
    eval "file_path=\${${file_var}:-}"
    [ -n "$file_path" ] || continue
    [ -f "$file_path" ] || { echo "$file_var points to a missing secret file" >&2; exit 1; }
    value="$(cat "$file_path")"
    [ -n "$value" ] || { echo "$file_var points to an empty secret file" >&2; exit 1; }
    export "$secret_var=$value"
    unset "$file_var"
done
