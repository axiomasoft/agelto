#!/usr/bin/env bash

set -euo pipefail

readonly STAGES=(verify-manifest create-isolated-target restore-postgres restore-object restore-config verify-readiness)

fail() {
    printf '[FAIL] RESTORE %s\n' "$*" >&2
    exit 1
}

mode=${1:-}
case "$mode" in
    check|fixture) shift ;;
    apply) fail 'apply requires the follow-up operator rehearsal authority' ;;
    *) exit 2 ;;
esac

target=''
manifest=''
adapter=''
lock_file=''
while (($#)); do
    case "$1" in
        --target) target=${2:-}; shift ;;
        --manifest) manifest=${2:-}; shift ;;
        --adapter) adapter=${2:-}; shift ;;
        --lock-file) lock_file=${2:-}; shift ;;
        *) exit 2 ;;
    esac
    shift
done

[[ $target =~ ^[a-z][a-z0-9_]*_rehearsal$ && $target != *production* && $target != *prod* ]] || fail 'target must be an allowlisted isolated *_rehearsal identity'

if [[ $mode == check ]]; then
    [[ -z $manifest && -z $adapter && -z $lock_file ]] || exit 2
    printf '%s\n' "${STAGES[@]}"
    exit 0
fi

[[ $manifest = /* && -f $manifest && ! -L $manifest ]] || fail 'manifest must be a regular absolute file'
[[ $adapter = /* && -x $adapter && -f $adapter && ! -L $adapter ]] || fail 'fixture adapter is invalid'
[[ $lock_file = /* && ! -L $lock_file ]] || fail 'lock file must be an absolute non-symlink path'
tmp_base=$(realpath -m "${TMPDIR:-/tmp}")
for path in "$manifest" "$adapter" "$lock_file"; do
    [[ $(realpath -m "$path") == "$tmp_base"/* ]] || fail 'fixture paths must live below TMPDIR'
done

declare -A values=()
while IFS='=' read -r key value; do
    case "$key" in schema|verified|manifest_sha256|source_backup_id) ;; *) fail "unknown manifest key $key" ;; esac
    [[ ! -v "values[$key]" ]] || fail "duplicate manifest key $key"
    values["$key"]=$value
done < "$manifest"
[[ ${values[schema]:-} == agelto.backup-manifest/v1 && ${values[verified]:-} == 1 ]] || fail 'manifest is not verified'
[[ ${values[manifest_sha256]:-} =~ ^[0-9a-f]{64}$ && ${values[source_backup_id]:-} =~ ^backup-[0-9]{8}T[0-9]{6}Z$ ]] || fail 'manifest identity is invalid'

mkdir -p "$(dirname "$lock_file")"
exec 9>"$lock_file"
flock -n 9 || fail 'another release/backup/rotation/restore operation holds the lock'
for stage in "${STAGES[@]}"; do
    "$adapter" restore "$stage" "$target" "${values[source_backup_id]}"
done
printf '[PASS] RESTORE fixture completed only against %s; runtime evidence remains empty\n' "$target"
