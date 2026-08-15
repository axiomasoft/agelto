#!/usr/bin/env bash

set -euo pipefail

readonly STAGES=(preflight data migration seed-base initial-admin workers assets readiness doctor browser)

fail() {
    printf '[FAIL] FIRST-DEPLOY %s\n' "$*" >&2
    exit 1
}

usage() {
    cat >&2 <<'EOF'
Usage:
  first-production-deploy.sh check
  first-production-deploy.sh fixture --adapter /tmp/fake-tool --state-file /tmp/state --candidate-sha FULL_SHA --profile-sha SHA256

The production apply path is intentionally owned by the follow-up operator rehearsal plan.
EOF
}

mode=${1:-}
case "$mode" in
    check|fixture) ;;
    apply) fail 'apply requires the follow-up operator rehearsal authority' ;;
    *) usage; exit 2 ;;
esac
shift

adapter=''
state_file=''
candidate_sha=''
profile_sha=''
while (($#)); do
    case "$1" in
        --adapter) adapter=${2:-}; shift ;;
        --state-file) state_file=${2:-}; shift ;;
        --candidate-sha) candidate_sha=${2:-}; shift ;;
        --profile-sha) profile_sha=${2:-}; shift ;;
        *) usage; exit 2 ;;
    esac
    shift
done

if [[ $mode == check ]]; then
    [[ -z $adapter && -z $state_file && -z $candidate_sha && -z $profile_sha ]] || { usage; exit 2; }
    cat <<'EOF'
preflight|check|profile,image-pair,secrets,repository|repair preflight and rerun
data|mutation|postgres,redis,minio healthy|resume at data
migration|mutation|schema migration receipt|resume at migration
seed-base|mutation|idempotent base-seed receipt|resume at seed-base
initial-admin|mutation|create-only admin receipt|resume at initial-admin
workers|mutation|queue,scheduler,reverb health|resume at workers
assets|mutation|assets and private-doc manifest|resume at assets
readiness|check|application readiness JSON|repair service and rerun
doctor|check|Doctor agent JSON|repair finding and rerun
browser|check|authorized HTTPS browser proof|repair route/auth and rerun
EOF
    exit 0
fi

[[ $adapter = /* && -x $adapter && -f $adapter && ! -L $adapter ]] || fail 'fixture adapter must be an executable regular file'
[[ $state_file = /* ]] || fail 'state file must be absolute'
[[ $candidate_sha =~ ^[0-9a-f]{40}$ ]] || fail 'candidate SHA must be 40 lowercase hex characters'
[[ $profile_sha =~ ^[0-9a-f]{64}$ ]] || fail 'profile SHA must be 64 lowercase hex characters'

tmp_base=$(realpath -m "${TMPDIR:-/tmp}")
resolved_state=$(realpath -m "$state_file")
resolved_adapter=$(realpath -m "$adapter")
[[ $resolved_state == "$tmp_base"/* && $resolved_adapter == "$tmp_base"/* ]] || fail 'fixture files must live below TMPDIR'
[[ ! -L $state_file ]] || fail 'state file must not be a symlink'
mkdir -p "$(dirname "$state_file")"
exec 9>"$state_file.lock"
flock -n 9 || fail 'another deploy/release/recovery operation holds the lock'

declare -A state=()
completed=()
if [[ -e $state_file ]]; then
    [[ -f $state_file && ! -L $state_file ]] || fail 'state must be a regular non-symlink file'
    permissions=$(stat -c '%a' "$state_file")
    (( (8#${permissions: -3} & 8#077) == 0 )) || fail 'state must be owner-only'
    while IFS='=' read -r key value; do
        case "$key" in schema|candidate_sha|profile_sha|completed) ;; *) fail "unknown state key $key" ;; esac
        [[ ! -v "state[$key]" ]] || fail "duplicate state key $key"
        state["$key"]=$value
    done < "$state_file"
    [[ ${state[schema]:-} == agelto.first-deploy-state/v1 ]] || fail 'state schema mismatch'
    [[ ${state[candidate_sha]:-} == "$candidate_sha" ]] || fail 'candidate SHA differs from the existing state'
    [[ ${state[profile_sha]:-} == "$profile_sha" ]] || fail 'profile SHA differs from the existing state'
    IFS=',' read -r -a completed <<<"${state[completed]:-}"
fi

contains_stage() {
    local expected=$1 item
    for item in "${completed[@]}"; do
        [[ $item == "$expected" ]] && return 0
    done
    return 1
}

write_state() {
    local temporary joined
    temporary=$(mktemp "${state_file}.tmp.XXXXXX")
    chmod 0600 "$temporary"
    joined=$(IFS=,; printf '%s' "${completed[*]}")
    {
        printf 'schema=agelto.first-deploy-state/v1\n'
        printf 'candidate_sha=%s\n' "$candidate_sha"
        printf 'profile_sha=%s\n' "$profile_sha"
        printf 'completed=%s\n' "$joined"
    } > "$temporary"
    mv "$temporary" "$state_file"
}

for stage in "${STAGES[@]}"; do
    contains_stage "$stage" && continue
    "$adapter" run "$stage" "$candidate_sha"
    completed+=("$stage")
    write_state
done

printf '[PASS] FIRST-DEPLOY fixture completed %s stages; runtime evidence remains empty\n' "${#STAGES[@]}"
