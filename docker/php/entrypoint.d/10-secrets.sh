#!/bin/sh
# Читаем Docker Swarm secrets в переменные окружения.
# Если переменная APP_KEY_FILE (и т.п.) указывает на файл, читаем его содержимое
# и экспортируем как APP_KEY, затем убираем _FILE-переменную.
# Работает только в Docker Swarm (docker stack deploy); при обычном compose без Swarm — нет-оп.
set -eu

for secret_var in APP_KEY DB_PASSWORD REDIS_PASSWORD MINIO_ROOT_PASSWORD; do
    file_var="${secret_var}_FILE"
    eval "file_path=\${${file_var}:-}"
    if [ -n "$file_path" ] && [ -f "$file_path" ]; then
        export "$secret_var"="$(cat "$file_path")"
        unset "$file_var"
    fi
done
