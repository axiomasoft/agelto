#!/usr/bin/env bash

set -euo pipefail

readonly root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly release="$root/docker/scripts/release-production.sh"
readonly rollback="$root/docker/scripts/rollback-production.sh"
readonly restore="$root/docker/scripts/restore-production.sh"
readonly rotation="$root/docker/scripts/rotate-production-secrets.sh"
readonly expected_release=(preflight backup compatibility drain promote readiness canary)

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

for script in "$release" "$rollback" "$restore" "$rotation"; do
    bash -n "$script"
done
mapfile -t actual_release < <("$release" check)
[[ ${actual_release[*]} == "${expected_release[*]}" ]] || fail 'release order is not the frozen backup-before-mutation sequence'

fixture_root=$(mktemp -d)
trap 'find "$fixture_root" -depth -delete' EXIT
adapter="$fixture_root/adapter"
cat > "$adapter" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
action=$1
stage=$2
identity=$3
printf '%s:%s:%s\n' "$action" "$stage" "$identity" >> "${LIFECYCLE_LOG:?}"
if [[ $action == run && ${FAIL_STAGE:-} == "$stage" ]]; then
    exit "${FAIL_STATUS:-41}"
fi
EOF
chmod 0700 "$adapter"

candidate=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
previous=sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
lock="$fixture_root/shared.lock"
state="$fixture_root/release.state"
export LIFECYCLE_LOG="$fixture_root/release.log"

"$release" fixture --adapter "$adapter" --state-file "$state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes >/dev/null
grep -qx 'completed=preflight,backup,compatibility,drain,promote,readiness,canary' "$state"
[[ $(wc -l < "$LIFECYCLE_LOG") -eq ${#expected_release[@]} ]]
state_hash=$(sha256sum "$state" | cut -d' ' -f1)
"$release" fixture --adapter "$adapter" --state-file "$state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes >/dev/null
[[ $(wc -l < "$LIFECYCLE_LOG") -eq ${#expected_release[@]} && $(sha256sum "$state" | cut -d' ' -f1) == "$state_hash" ]] || fail 'terminal release rerun was not idempotent'

failure_state="$fixture_root/failure.state"
export LIFECYCLE_LOG="$fixture_root/failure.log"
expect_fail 'readiness failure' env FAIL_STAGE=readiness LIFECYCLE_LOG="$LIFECYCLE_LOG" \
    "$release" fixture --adapter "$adapter" --state-file "$failure_state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes
mapfile -t reversed < <(sed -n 's/^rollback:\([^:]*\):.*/\1/p' "$LIFECYCLE_LOG")
[[ ${reversed[*]} == 'promote drain' ]] || fail 'failed release did not reverse mutations in reverse order'
grep -qx 'completed=preflight,backup,compatibility' "$failure_state" || fail 'failed release retained a mutation in state'
"$release" fixture --adapter "$adapter" --state-file "$failure_state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes >/dev/null
grep -qx 'completed=preflight,backup,compatibility,drain,promote,readiness,canary' "$failure_state"

export LIFECYCLE_LOG="$fixture_root/timeout.log"
expect_fail 'bounded drain timeout' env FAIL_STAGE=drain FAIL_STATUS=124 LIFECYCLE_LOG="$LIFECYCLE_LOG" \
    "$release" fixture --adapter "$adapter" --state-file "$fixture_root/timeout.state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes
mapfile -t drain_reversed < <(sed -n 's/^rollback:\([^:]*\):.*/\1/p' "$LIFECYCLE_LOG")
[[ ${drain_reversed[*]} == 'drain' ]] || fail 'failed drain stage was not compensated during rollback'
grep -qx 'completed=preflight,backup,compatibility' "$fixture_root/timeout.state" || fail 'failed drain retained mutation state'

canary_state="$fixture_root/canary.state"
export LIFECYCLE_LOG="$fixture_root/canary.log"
expect_fail 'canary failure' env FAIL_STAGE=canary LIFECYCLE_LOG="$LIFECYCLE_LOG" \
    "$release" fixture --adapter "$adapter" --state-file "$canary_state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes
grep -qx 'completed=preflight,backup,compatibility' "$canary_state" || fail 'canary rollback did not invalidate readiness'
: > "$LIFECYCLE_LOG"
"$release" fixture --adapter "$adapter" --state-file "$canary_state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes >/dev/null
grep -qx "run:readiness:$candidate" "$LIFECYCLE_LOG" || fail 'resume after canary rollback skipped readiness'

crash_state="$fixture_root/crash.state"
cat > "$crash_state" <<EOF
schema=agelto.release-state/v1
candidate_digest=$candidate
previous_digest=$previous
schema_compatible=yes
completed=preflight,backup,compatibility,drain
in_progress=promote
EOF
chmod 0600 "$crash_state"
export LIFECYCLE_LOG="$fixture_root/crash.log"
"$release" fixture --adapter "$adapter" --state-file "$crash_state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes >/dev/null
mapfile -t crash_reversed < <(sed -n 's/^rollback:\([^:]*\):.*/\1/p' "$LIFECYCLE_LOG")
[[ ${crash_reversed[*]} == 'promote drain' ]] || fail 'crash recovery did not compensate in_progress promote and completed drain'
grep -qx 'completed=preflight,backup,compatibility,drain,promote,readiness,canary' "$crash_state"

corrupt_state="$fixture_root/corrupt.state"
cat > "$corrupt_state" <<EOF
schema=agelto.release-state/v1
candidate_digest=$candidate
previous_digest=$previous
schema_compatible=yes
completed=preflight,promote
EOF
chmod 0600 "$corrupt_state"
expect_fail 'out-of-order completed stages' "$release" fixture --adapter "$adapter" --state-file "$corrupt_state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes

expect_fail 'unknown schema compatibility' "$release" fixture --adapter "$adapter" --state-file "$fixture_root/unknown.state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible unknown
expect_fail 'same release digest' "$release" fixture --adapter "$adapter" --state-file "$fixture_root/same.state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$candidate" --schema-compatible yes
exec 8>"$lock"
flock -n 8
expect_fail 'release lock contention' "$release" fixture --adapter "$adapter" --state-file "$fixture_root/lock.state" --lock-file "$lock" \
    --candidate-digest "$candidate" --previous-digest "$previous" --schema-compatible yes
expect_fail 'rollback lock contention' "$rollback" fixture --adapter "$adapter" --previous-digest "$previous" --schema-compatible yes --lock-file "$lock"
flock -u 8

export LIFECYCLE_LOG="$fixture_root/rollback.log"
"$rollback" fixture --adapter "$adapter" --previous-digest "$previous" --schema-compatible yes >/dev/null
mapfile -t rollback_stages < <(sed -n 's/^rollback:\([^:]*\):.*/\1/p' "$LIFECYCLE_LOG")
[[ ${rollback_stages[*]} == 'stop compatibility previous-digest promote readiness' ]] || fail 'rollback stage order drifted'
expect_fail 'rollback with unknown compatibility' "$rollback" fixture --adapter "$adapter" --previous-digest "$previous" --schema-compatible unknown

manifest="$fixture_root/backup.manifest"
cat > "$manifest" <<'EOF'
schema=agelto.backup-manifest/v1
verified=1
manifest_sha256=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
source_backup_id=backup-20260815T000000Z
EOF
export LIFECYCLE_LOG="$fixture_root/restore.log"
"$restore" fixture --target agelto_rehearsal --manifest "$manifest" --adapter "$adapter" --lock-file "$lock" >/dev/null
[[ $(grep -c '^restore:' "$LIFECYCLE_LOG") -eq 6 ]] || fail 'restore did not execute the closed isolated sequence'
expect_fail 'production restore target' "$restore" check --target production
expect_fail 'prod-like rehearsal target' "$restore" check --target agelto_prod_rehearsal
sed -i 's/verified=1/verified=0/' "$manifest"
expect_fail 'unverified backup manifest' "$restore" fixture --target agelto_rehearsal --manifest "$manifest" --adapter "$adapter" --lock-file "$lock"

bash "$root/docker/tests/secret-rotation-contract.sh"

expect_fail 'live release apply' "$release" apply
expect_fail 'live rollback apply' "$rollback" apply
expect_fail 'live restore apply' "$restore" apply
! rg -n -- 'git pull|docker build|down --volumes|eval|\|\| true|pg_restore[[:space:]]+--clean|migrate:fresh|rm[[:space:]]+-rf' \
    "$release" "$rollback" "$restore" "$rotation" >/dev/null
grep -F 'backup before the first mutation' "$root/docs/operators/production/releases.md" >/dev/null
grep -F 'reverse consumer order' "$root/docs/operators/production/secret-rotation.md" >/dev/null
grep -F 'isolated `*_rehearsal`' "$root/docs/operators/production/rollback-restore.md" >/dev/null

printf 'Release and recovery contract passed\n'
