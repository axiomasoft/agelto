#!/usr/bin/env bash

set -euo pipefail

readonly root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly script="$root/docker/scripts/first-production-deploy.sh"
readonly stages=(preflight data migration seed-base initial-admin workers assets readiness doctor browser)

fail() {
    printf '[FAIL] %s\n' "$*" >&2
    exit 1
}

expect_fail() {
    local label=$1
    shift
    if "$@" >/dev/null 2>&1; then
        fail "$label unexpectedly passed"
    fi
}

bash -n "$script"
mapfile -t described < <("$script" check | cut -d'|' -f1)
[[ ${described[*]} == "${stages[*]}" ]] || fail 'check mode does not expose the closed stage order'
"$script" check | grep -Fx 'workers|mutation|horizon,scheduler,process-relay,process-reconciler,process-feeder,process-projector health|resume at workers' >/dev/null || fail 'worker health matrix differs from production services'

fixture_root=$(mktemp -d)
trap 'find "$fixture_root" -depth -delete' EXIT
adapter="$fixture_root/adapter"
cat > "$adapter" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ $1 == run && $3 =~ ^[0-9a-f]{40}$ ]] || exit 90
printf '%s\n' "$2" >> "${CALL_LOG:?}"
[[ ${FAIL_STAGE:-} != "$2" ]] || exit 41
EOF
chmod 0700 "$adapter"

candidate=0123456789abcdef0123456789abcdef01234567
profile_sha=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
state="$fixture_root/state"
export CALL_LOG="$fixture_root/calls"

"$script" fixture --adapter "$adapter" --state-file "$state" --candidate-sha "$candidate" --profile-sha "$profile_sha" >/dev/null
[[ $(wc -l < "$CALL_LOG") -eq ${#stages[@]} ]] || fail 'happy path did not execute every stage exactly once'
expected_completed=$(IFS=,; printf '%s' "${stages[*]}")
grep -qx "completed=$expected_completed" "$state" || fail 'terminal state is incomplete'
state_hash=$(sha256sum "$state" | cut -d' ' -f1)
"$script" fixture --adapter "$adapter" --state-file "$state" --candidate-sha "$candidate" --profile-sha "$profile_sha" >/dev/null
[[ $(wc -l < "$CALL_LOG") -eq ${#stages[@]} ]] || fail 'second run repeated a completed stage'
[[ $(sha256sum "$state" | cut -d' ' -f1) == "$state_hash" ]] || fail 'second run changed terminal state'

for failed_index in "${!stages[@]}"; do
    failed_stage=${stages[$failed_index]}
    case_root="$fixture_root/failure-$failed_index"
    mkdir "$case_root"
    case_state="$case_root/state"
    export CALL_LOG="$case_root/calls"
    FAIL_STAGE="$failed_stage" expect_fail "failure at $failed_stage" \
        "$script" fixture --adapter "$adapter" --state-file "$case_state" --candidate-sha "$candidate" --profile-sha "$profile_sha"
    if (( failed_index == 0 )); then
        [[ ! -e $case_state ]] || fail 'failed first stage must not claim completion'
    else
        completed_count=$(sed -n 's/^completed=//p' "$case_state" | tr ',' '\n' | sed '/^$/d' | wc -l)
        [[ $completed_count -eq $failed_index ]] || fail "failure at $failed_stage recorded the wrong prefix"
    fi
    "$script" fixture --adapter "$adapter" --state-file "$case_state" --candidate-sha "$candidate" --profile-sha "$profile_sha" >/dev/null
    for ((prior=0; prior<failed_index; prior++)); do
        [[ $(grep -Fxc "${stages[$prior]}" "$CALL_LOG") -eq 1 ]] || fail "resume repeated ${stages[$prior]}"
    done
    [[ $(grep -Fxc "$failed_stage" "$CALL_LOG") -eq 2 ]] || fail "resume did not retry $failed_stage exactly once"
done

export CALL_LOG="$fixture_root/mismatch-calls"
expect_fail 'candidate mismatch' \
    "$script" fixture --adapter "$adapter" --state-file "$state" --candidate-sha fedcba9876543210fedcba9876543210fedcba98 --profile-sha "$profile_sha"
corrupt_state="$fixture_root/corrupt-state"
cat > "$corrupt_state" <<EOF
schema=agelto.first-deploy-state/v1
candidate_sha=$candidate
profile_sha=$profile_sha
completed=preflight,migration
EOF
chmod 0600 "$corrupt_state"
expect_fail 'out-of-order completed stages' \
    "$script" fixture --adapter "$adapter" --state-file "$corrupt_state" --candidate-sha "$candidate" --profile-sha "$profile_sha"
shared_lock="$fixture_root/shared.lock"
exec 8>"$shared_lock"
flock -n 8
expect_fail 'shared lock contention' \
    "$script" fixture --adapter "$adapter" --state-file "$fixture_root/locked-state" --lock-file "$shared_lock" --candidate-sha "$candidate" --profile-sha "$profile_sha"
flock -u 8
ln -s "$state" "$fixture_root/state-link"
expect_fail 'state symlink' \
    "$script" fixture --adapter "$adapter" --state-file "$fixture_root/state-link" --candidate-sha "$candidate" --profile-sha "$profile_sha"
expect_fail 'live apply' "$script" apply
expect_fail 'unknown mode' "$script" unknown

! rg -n -- 'docker build|migrate:fresh|seed-demo|down --volumes|eval|\|\| true|password|secret.*(argv|echo)' "$script" >/dev/null
! rg -n -- 'SENTINEL' "$state" "$fixture_root/calls" >/dev/null
grep -F 'он не запускает production' "$root/docs/operators/production/first-deploy.md" >/dev/null
grep -F 'failure state and resume' "$root/docs/operators/production/troubleshooting.md" >/dev/null

printf 'First deploy contract passed\n'
