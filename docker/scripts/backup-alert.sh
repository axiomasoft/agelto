#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf '[FAIL] BACKUP-ALERT %s\n' "$*" >&2
    exit 1
}

case ${1:-} in
    check) ;;
    apply) fail 'apply requires the follow-up operator rehearsal authority' ;;
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
