#!/usr/bin/env bash
# Safe launcher: never source a dotenv file and never run the payload when disabled.
set -euo pipefail

fail() { printf 'Agelto backup config: %s\n' "$1" >&2; exit 2; }
[[ $# -ge 3 && $2 == -- ]] || fail 'usage: ENV_FILE -- COMMAND [ARGS...]'
profile=$1
shift 2
[[ $profile == /* && -f $profile && ! -L $profile ]] || fail 'expected a regular absolute env file'
enabled=''
seen=0
while IFS= read -r line || [[ -n $line ]]; do
    [[ $line =~ ^[[:space:]]*(#.*)?$ ]] && continue
    [[ $line == BACKUP_ENABLED=true || $line == BACKUP_ENABLED=false ]] || fail 'only BACKUP_ENABLED=true|false is supported'
    (( seen == 0 )) || fail 'duplicate BACKUP_ENABLED'
    enabled=${line#BACKUP_ENABLED=}
    seen=1
done < "$profile"
(( seen == 1 )) || fail 'BACKUP_ENABLED is required'
if [[ $enabled == false ]]; then
    printf 'Agelto backups disabled by BACKUP_ENABLED=false; no backup/watchdog payload executed\n'
    exit 0
fi
exec "$@"
