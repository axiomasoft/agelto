#!/usr/bin/env bash

set -euo pipefail

readonly STAGES=(stop compatibility previous-digest promote readiness)

fail() {
    printf '[FAIL] ROLLBACK %s\n' "$*" >&2
    exit 1
}

mode=${1:-}
case "$mode" in
    check) [[ $# -eq 1 ]] || exit 2; printf '%s\n' "${STAGES[@]}"; exit 0 ;;
    fixture) shift ;;
    apply) fail 'apply requires the follow-up operator rehearsal authority' ;;
    *) exit 2 ;;
esac

adapter=''
previous_digest=''
schema_compatible=''
lock_file=''
while (($#)); do
    case "$1" in
        --adapter) adapter=${2:-}; shift ;;
        --previous-digest) previous_digest=${2:-}; shift ;;
        --schema-compatible) schema_compatible=${2:-}; shift ;;
        --lock-file) lock_file=${2:-}; shift ;;
        *) exit 2 ;;
    esac
    shift
done

[[ $adapter = /* && -x $adapter && ! -L $adapter ]] || fail 'fixture adapter is invalid'
tmp_base=$(realpath -m "${TMPDIR:-/tmp}")
[[ $(realpath -m "$adapter") == "$tmp_base"/* ]] || fail 'fixture adapter must live below TMPDIR'
[[ $previous_digest =~ ^sha256:[0-9a-f]{64}$ ]] || fail 'previous digest must be immutable'
[[ $schema_compatible == yes ]] || fail 'rollback stops when schema compatibility is unknown or red'
if [[ -z $lock_file ]]; then
    lock_file="$(dirname "$adapter")/.agelto-ops.lock"
fi
[[ $lock_file = /* && ! -L $lock_file && $(realpath -m "$lock_file") == "$tmp_base"/* ]] || fail 'lock file must be an absolute non-symlink path below TMPDIR'
mkdir -p "$(dirname "$lock_file")"
exec 9>"$lock_file"
flock -n 9 || fail 'another release/backup/rotation/restore operation holds the lock'

for stage in "${STAGES[@]}"; do
    "$adapter" rollback "$stage" "$previous_digest"
done
printf '[PASS] ROLLBACK fixture restored the previous immutable digest after compatibility proof\n'
