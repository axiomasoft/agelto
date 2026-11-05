#!/usr/bin/env bash

set -euo pipefail

readonly STAGES=(preflight backup compatibility drain promote readiness canary)
readonly MUTATION_STAGES=(drain promote)
readonly POST_MUTATION_STAGES=(drain promote readiness canary)

fail() {
    printf '[FAIL] RELEASE %s\n' "$*" >&2
    exit 1
}

usage() {
    cat >&2 <<'EOF'
Usage:
  release-production.sh check
  release-production.sh fixture --adapter /tmp/fake-tool --state-file /tmp/state --lock-file /tmp/lock --candidate-digest sha256:HEX --previous-digest sha256:HEX --schema-compatible yes

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
lock_file=''
candidate_digest=''
previous_digest=''
schema_compatible=''
while (($#)); do
    case "$1" in
        --adapter) adapter=${2:-}; shift ;;
        --state-file) state_file=${2:-}; shift ;;
        --lock-file) lock_file=${2:-}; shift ;;
        --candidate-digest) candidate_digest=${2:-}; shift ;;
        --previous-digest) previous_digest=${2:-}; shift ;;
        --schema-compatible) schema_compatible=${2:-}; shift ;;
        *) usage; exit 2 ;;
    esac
    shift
done

if [[ $mode == check ]]; then
    [[ -z $adapter && -z $state_file && -z $lock_file && -z $candidate_digest && -z $previous_digest && -z $schema_compatible ]] || { usage; exit 2; }
    printf '%s\n' "${STAGES[@]}"
    exit 0
fi

[[ $adapter = /* && -x $adapter && -f $adapter && ! -L $adapter ]] || fail 'fixture adapter must be an executable regular file'
[[ $state_file = /* && $lock_file = /* ]] || fail 'state and lock files must be absolute'
[[ $candidate_digest =~ ^sha256:[0-9a-f]{64}$ && $previous_digest =~ ^sha256:[0-9a-f]{64}$ ]] || fail 'image digests must be immutable sha256 values'
[[ $candidate_digest != "$previous_digest" ]] || fail 'candidate and previous digests must differ'
[[ $schema_compatible == yes ]] || fail 'schema compatibility is unknown or red'

tmp_base=$(realpath -m "${TMPDIR:-/tmp}")
for path in "$adapter" "$state_file" "$lock_file"; do
    [[ $(realpath -m "$path") == "$tmp_base"/* ]] || fail 'fixture paths must live below TMPDIR'
done
[[ ! -L $state_file && ! -L $lock_file ]] || fail 'state and lock files must not be symlinks'
mkdir -p "$(dirname "$state_file")" "$(dirname "$lock_file")"
exec 9>"$lock_file"
flock -n 9 || fail 'another release/backup/rotation/restore operation holds the lock'

declare -A state=()
completed=()
in_progress=''
if [[ -e $state_file ]]; then
    [[ -f $state_file && ! -L $state_file ]] || fail 'state must be a regular non-symlink file'
    permissions=$(stat -c '%a' "$state_file")
    (( (8#${permissions: -3} & 8#077) == 0 )) || fail 'state must be owner-only'
    while IFS='=' read -r key value; do
        case "$key" in schema|candidate_digest|previous_digest|schema_compatible|completed|in_progress) ;; *) fail "unknown state key $key" ;; esac
        [[ ! -v "state[$key]" ]] || fail "duplicate state key $key"
        state["$key"]=$value
    done < "$state_file"
    [[ ${state[schema]:-} == agelto.release-state/v1 ]] || fail 'state schema mismatch'
    [[ ${state[candidate_digest]:-} == "$candidate_digest" && ${state[previous_digest]:-} == "$previous_digest" ]] || fail 'release digest differs from existing state'
    [[ ${state[schema_compatible]:-} == yes ]] || fail 'existing state lacks a green compatibility result'
    if [[ -n ${state[completed]:-} ]]; then
        IFS=',' read -r -a completed <<<"${state[completed]}"
    fi
    (( ${#completed[@]} <= ${#STAGES[@]} )) || fail 'existing state has more completed stages than defined'
    for idx in "${!completed[@]}"; do
        [[ ${completed[$idx]} == "${STAGES[$idx]}" ]] || fail "invalid or out-of-order completed stage ${completed[$idx]}"
    done
    in_progress=${state[in_progress]:-}
    if [[ -n $in_progress ]]; then
        (( ${#completed[@]} < ${#STAGES[@]} )) && [[ $in_progress == "${STAGES[${#completed[@]}]}" ]] || fail "invalid in_progress stage $in_progress"
    fi
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
        printf 'schema=agelto.release-state/v1\n'
        printf 'candidate_digest=%s\n' "$candidate_digest"
        printf 'previous_digest=%s\n' "$previous_digest"
        printf 'schema_compatible=yes\n'
        printf 'completed=%s\n' "$joined"
        if [[ -n $in_progress ]]; then
            printf 'in_progress=%s\n' "$in_progress"
        fi
    } > "$temporary"
    mv "$temporary" "$state_file"
}

rollback_mutations() {
    local failed_stage=${1:-} index stage retained=()
    if [[ -n $failed_stage ]] && ! contains_stage "$failed_stage"; then
        case " ${MUTATION_STAGES[*]} " in
            *" $failed_stage "*) "$adapter" rollback "$failed_stage" "$previous_digest" ;;
        esac
    fi
    for ((index=${#completed[@]}-1; index>=0; index--)); do
        stage=${completed[$index]}
        case " ${MUTATION_STAGES[*]} " in
            *" $stage "*) "$adapter" rollback "$stage" "$previous_digest" ;;
        esac
    done
    for stage in "${completed[@]}"; do
        case " ${POST_MUTATION_STAGES[*]} " in *" $stage "*) ;; *) retained+=("$stage") ;; esac
    done
    completed=("${retained[@]}")
    in_progress=''
    if ((${#completed[@]} == 0)); then
        rm -f "$state_file"
    else
        write_state
    fi
}

if [[ -n $in_progress ]]; then
    rollback_mutations "$in_progress"
fi

for stage in "${STAGES[@]}"; do
    contains_stage "$stage" && continue
    case " ${MUTATION_STAGES[*]} " in
        *" $stage "*)
            in_progress=$stage
            write_state
            ;;
    esac
    set +e
    "$adapter" run "$stage" "$candidate_digest" "$previous_digest"
    status=$?
    set -e
    if ((status != 0)); then
        rollback_mutations "$stage"
        fail "stage $stage failed with status $status; completed mutations were reversed"
    fi
    in_progress=''
    completed+=("$stage")
    write_state
done

printf '[PASS] RELEASE fixture promoted an immutable pair and passed readiness/canary; runtime evidence remains empty\n'
