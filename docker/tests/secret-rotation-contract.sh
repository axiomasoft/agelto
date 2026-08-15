#!/usr/bin/env bash

set -euo pipefail

readonly root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly script="$root/docker/scripts/rotate-production-secrets.sh"

fail() {
    printf '[FAIL] P12.5-ROTATION %s\n' "$*" >&2
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
matrix=$("$script" check)
for credential in APP DB REDIS MINIO; do
    grep -q "^$credential:" <<<"$matrix" || fail "$credential is absent from the dependency matrix"
done

fixture_root=$(mktemp -d)
trap 'find "$fixture_root" -depth -delete' EXIT
before="$fixture_root/before"
next="$fixture_root/next"
printf 'current-SENTINEL-secret\n' > "$before"
printf 'next-SENTINEL-secret\n' > "$next"
chmod 0600 "$before" "$next"

adapter="$fixture_root/adapter"
cat > "$adapter" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
action=$1
credential=$2
consumer=${3:-}
printf '%s:%s:%s\n' "$action" "$credential" "$consumer" >> "${ROTATION_LOG:?}"
[[ $action != verify-consumer || ${FAIL_CONSUMER:-} != "$consumer" ]] || exit 41
EOF
chmod 0700 "$adapter"

lock="$fixture_root/shared.lock"
for credential in APP DB REDIS MINIO; do
    export ROTATION_LOG="$fixture_root/$credential.log"
    state="$fixture_root/$credential.state"
    "$script" fixture --credential "$credential" --before-file "$before" --next-file "$next" \
        --adapter "$adapter" --state-file "$state" --lock-file "$lock" >/dev/null
    grep -qx "credential=$credential" "$state"
    grep -qx 'status=applied' "$state"
    grep -q '^retire-old:' "$ROTATION_LOG" || fail "$credential did not retire the old credential after green consumers"
    ! rg -n 'SENTINEL' "$ROTATION_LOG" "$state" >/dev/null
done

export ROTATION_LOG="$fixture_root/failure.log"
expect_fail 'partial DB cutover' env FAIL_CONSUMER=scheduler ROTATION_LOG="$ROTATION_LOG" \
    "$script" fixture --credential DB --before-file "$before" --next-file "$next" \
    --adapter "$adapter" --state-file "$fixture_root/failure.state" --lock-file "$lock"
! grep -q '^retire-old:' "$ROTATION_LOG"
mapfile -t rollback_order < <(sed -n 's/^rollback-consumer:DB://p' "$ROTATION_LOG")
[[ ${rollback_order[*]} == 'queue app' ]] || fail 'partial cutover did not roll back consumers in reverse order'

cp "$before" "$fixture_root/same"
chmod 0600 "$fixture_root/same"
expect_fail 'unchanged credential' "$script" fixture --credential APP --before-file "$before" --next-file "$fixture_root/same" \
    --adapter "$adapter" --state-file "$fixture_root/same.state" --lock-file "$lock"
chmod 0644 "$next"
expect_fail 'weak next credential permissions' "$script" fixture --credential APP --before-file "$before" --next-file "$next" \
    --adapter "$adapter" --state-file "$fixture_root/weak.state" --lock-file "$lock"
chmod 0600 "$next"
expect_fail 'unknown credential enum' "$script" fixture --credential SMTP --before-file "$before" --next-file "$next" \
    --adapter "$adapter" --state-file "$fixture_root/unknown.state" --lock-file "$lock"

exec 8>"$lock"
flock -n 8
expect_fail 'rotation lock contention' "$script" fixture --credential APP --before-file "$before" --next-file "$next" \
    --adapter "$adapter" --state-file "$fixture_root/locked.state" --lock-file "$lock"
flock -u 8

expect_fail 'live apply' "$script" apply
! rg -n -- 'eval|\|\| true|password|token|printf.*(before_file|next_file)|cat.*(before_file|next_file)' "$script" >/dev/null
printf 'Secret rotation contract passed\n'
