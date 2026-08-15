#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf '[FAIL] BACKUP-WATCHDOG %s\n' "$*" >&2
    exit 1
}

[[ ${1:-} == check ]] || { printf 'Usage: backup-watchdog.sh check --status-file /absolute/path --max-age SECONDS [--now EPOCH]\n' >&2; exit 2; }
shift

status_file=''
max_age=''
now=$(date +%s)
while (($#)); do
    case "$1" in
        --status-file) status_file=${2:-}; shift ;;
        --max-age) max_age=${2:-}; shift ;;
        --now) now=${2:-}; shift ;;
        *) exit 2 ;;
    esac
    shift
done

[[ $status_file = /* && -f $status_file && ! -L $status_file ]] || fail 'status file must be a regular absolute path'
[[ $max_age =~ ^[1-9][0-9]*$ && $now =~ ^[0-9]+$ ]] || fail 'max-age and now must be positive epochs'

declare -A state=()
while IFS='=' read -r key value; do
    case "$key" in completed_at|verified|offhost|manifest_sha256) ;; *) fail "unknown status key $key" ;; esac
    [[ ! -v "state[$key]" ]] || fail "duplicate status key $key"
    state["$key"]=$value
done < "$status_file"

[[ ${state[completed_at]:-} =~ ^[0-9]+$ ]] || fail 'completed_at is missing or invalid'
[[ ${state[verified]:-} == 1 && ${state[offhost]:-} == 1 ]] || fail 'latest backup is not verified and off-host'
[[ ${state[manifest_sha256]:-} =~ ^[0-9a-f]{64}$ ]] || fail 'manifest hash is missing or invalid'
(( now >= state[completed_at] && now - state[completed_at] <= max_age )) || fail 'latest verified backup is stale'

printf '[PASS] BACKUP-WATCHDOG latest verified off-host backup is fresh\n'
