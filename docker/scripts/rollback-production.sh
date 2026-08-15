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
while (($#)); do
    case "$1" in
        --adapter) adapter=${2:-}; shift ;;
        --previous-digest) previous_digest=${2:-}; shift ;;
        --schema-compatible) schema_compatible=${2:-}; shift ;;
        *) exit 2 ;;
    esac
    shift
done

[[ $adapter = /* && -x $adapter && ! -L $adapter ]] || fail 'fixture adapter is invalid'
[[ $(realpath -m "$adapter") == "$(realpath -m "${TMPDIR:-/tmp}")"/* ]] || fail 'fixture adapter must live below TMPDIR'
[[ $previous_digest =~ ^sha256:[0-9a-f]{64}$ ]] || fail 'previous digest must be immutable'
[[ $schema_compatible == yes ]] || fail 'rollback stops when schema compatibility is unknown or red'

for stage in "${STAGES[@]}"; do
    "$adapter" rollback "$stage" "$previous_digest"
done
printf '[PASS] ROLLBACK fixture restored the previous immutable digest after compatibility proof\n'
