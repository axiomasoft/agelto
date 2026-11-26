#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf '[FAIL] BACKUP-ALERT %s\n' "$*" >&2
    exit 1
}

case ${1:-} in
    check) ;;
    apply)
        [[ $# -eq 7 && $2 == --event && $4 == --endpoint-file && $5 == /etc/agelto/backup-alert-url \
            && $6 == --authority-file && $7 == /etc/agelto/operator-rehearsal-authority \
            && -s $7 && -f $7 && ! -L $7 ]] || fail 'apply requires the operator rehearsal authority'
        read -r owner permissions < <(stat -c '%u %a' "$7")
        [[ $owner == 0 ]] && (( (8#$permissions & 022) == 0 )) || fail 'operator authority must be controlled by root'
        [[ $(id -un) == deploy ]] || fail 'apply must run as deploy'
        case "$3" in
            agelto-backup.service) event='backup:failed:BACKUP_FAILED' ;;
            agelto-backup-watchdog.service) event='watchdog:stale:BACKUP_STALE' ;;
            *) fail 'unknown backup unit' ;;
        esac
        [[ -f $5 && ! -L $5 && -s $5 ]] || fail 'endpoint must be a regular nonempty file'
        permissions=$(stat -c '%a' "$5")
        (( (8#$permissions & 077) == 0 )) || fail 'endpoint file must be owner-only'
        endpoint=$(<"$5")
        [[ $endpoint =~ ^https://[^[:space:]\"\\]+$ ]] || fail 'endpoint must be an HTTPS URL'
        printf 'url = "%s"\n' "$endpoint" | curl -q --config - --proto '=https' --max-time 30 \
            --silent --fail --output /dev/null --header 'Content-Type: application/json' \
            --data-binary "{\"event\":\"$event\"}" 2>/dev/null || fail 'alert delivery failed'
        exit 0
        ;;
    *) printf 'Usage: backup-alert.sh check --event-file /absolute/path --endpoint-file /absolute/path [--adapter /absolute/fake-tool]\n' >&2; exit 2 ;;
esac
shift

event_file=''
endpoint_file=''
adapter=''
while (($#)); do
    case "$1" in
        --event-file) event_file=${2:-}; shift ;;
        --endpoint-file) endpoint_file=${2:-}; shift ;;
        --adapter) adapter=${2:-}; shift ;;
        *) exit 2 ;;
    esac
    shift
done

for path in "$event_file" "$endpoint_file"; do
    [[ $path = /* && -f $path && ! -L $path ]] || fail 'event and endpoint must be regular absolute files'
done
permissions=$(stat -c '%a' "$endpoint_file")
(( (8#${permissions: -3} & 8#077) == 0 )) || fail 'endpoint file must be owner-only'

event=$(<"$event_file")
[[ $event =~ ^(backup|watchdog):(failed|stale):[A-Z0-9_-]+$ ]] || fail 'event does not match the closed redacted schema'
[[ -s $endpoint_file ]] || fail 'endpoint file is empty'

if [[ -n $adapter ]]; then
    [[ $adapter = /* && -x $adapter && ! -L $adapter ]] || fail 'adapter must be an executable regular file'
    "$adapter" --endpoint-file "$endpoint_file" --event-file "$event_file"
fi

printf '[PASS] BACKUP-ALERT event schema and secret-file transport are valid (endpoint redacted)\n'
