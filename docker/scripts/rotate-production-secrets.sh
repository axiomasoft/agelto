#!/usr/bin/env bash

set -euo pipefail

# Compose secret files: app_key, db_password, redis_password, minio_app_password, minio_root_password.
readonly CREDENTIALS=(APP DB REDIS MINIO MINIO_ROOT)
readonly APP_CONSUMERS=(app horizon scheduler process-relay process-reconciler process-feeder process-projector doctor)

consumers_for() {
    case "$1" in
        APP) consumers=("${APP_CONSUMERS[@]}") ;;
        DB) consumers=("${APP_CONSUMERS[@]}" postgres) ;;
        REDIS) consumers=("${APP_CONSUMERS[@]}" redis redis-cache redis-session) ;;
        MINIO) consumers=("${APP_CONSUMERS[@]}") ;;
        MINIO_ROOT) consumers=(minio) ;;
    esac
}

fail() {
    printf '[FAIL] ROTATION %s\n' "$*" >&2
    exit 1
}

mode=${1:-}
case "$mode" in
    check)
        [[ $# -eq 1 ]] || exit 2
        for item in "${CREDENTIALS[@]}"; do
            consumers_for "$item"
            (IFS=,; printf '%s:%s\n' "$item" "${consumers[*]}")
        done
        exit 0 ;;
    fixture) shift ;;
    apply) fail 'apply requires the follow-up operator rehearsal authority' ;;
    *) exit 2 ;;
esac

credential=''
before_file=''
next_file=''
adapter=''
state_file=''
lock_file=''
while (($#)); do
    case "$1" in
        --credential) credential=${2:-}; shift ;;
        --before-file) before_file=${2:-}; shift ;;
        --next-file) next_file=${2:-}; shift ;;
        --adapter) adapter=${2:-}; shift ;;
        --state-file) state_file=${2:-}; shift ;;
        --lock-file) lock_file=${2:-}; shift ;;
        *) exit 2 ;;
    esac
    shift
done

case " ${CREDENTIALS[*]} " in *" $credential "*) ;; *) fail 'credential is outside the closed APP/DB/REDIS/MINIO/MINIO_ROOT enum' ;; esac
for path in "$before_file" "$next_file"; do
    [[ $path = /* && -f $path && ! -L $path ]] || fail 'credential inputs must be regular absolute files'
    permissions=$(stat -c '%a' "$path")
    (( (8#${permissions: -3} & 8#077) == 0 )) || fail 'credential inputs must be owner-only'
    [[ -s $path ]] || fail 'credential input is empty'
done
[[ $adapter = /* && -x $adapter && ! -L $adapter && $state_file = /* && $lock_file = /* ]] || fail 'fixture adapter/state/lock arguments are invalid'
tmp_base=$(realpath -m "${TMPDIR:-/tmp}")
for path in "$before_file" "$next_file" "$adapter" "$state_file" "$lock_file"; do
    [[ $(realpath -m "$path") == "$tmp_base"/* ]] || fail 'fixture paths must live below TMPDIR'
done
[[ ! -L $state_file && ! -L $lock_file ]] || fail 'state and lock files must not be symlinks'

before_hash=$(sha256sum "$before_file" | cut -d' ' -f1)
next_hash=$(sha256sum "$next_file" | cut -d' ' -f1)
[[ $before_hash != "$next_hash" ]] || fail 'next credential must differ from the current credential'

consumers_for "$credential"

mkdir -p "$(dirname "$state_file")" "$(dirname "$lock_file")"
exec 9>"$lock_file"
flock -n 9 || fail 'another release/backup/rotation/restore operation holds the lock'

completed=()
"$adapter" stage-next "$credential" "$next_file"
for consumer in "${consumers[@]}"; do
    set +e
    "$adapter" verify-consumer "$credential" "$consumer" "$next_file"
    status=$?
    set -e
    if ((status != 0)); then
        for ((index=${#completed[@]}-1; index>=0; index--)); do
            "$adapter" rollback-consumer "$credential" "${completed[$index]}" "$before_file"
        done
        fail "consumer $consumer rejected the next credential; verified consumers were rolled back"
    fi
    completed+=("$consumer")
done
"$adapter" retire-old "$credential" "$before_file"

temporary=$(mktemp "${state_file}.tmp.XXXXXX")
chmod 0600 "$temporary"
{
    printf 'schema=agelto.secret-rotation-state/v1\n'
    printf 'credential=%s\n' "$credential"
    printf 'before_sha256=%s\n' "$before_hash"
    printf 'next_sha256=%s\n' "$next_hash"
    printf 'status=applied\n'
    printf 'consumers=%s\n' "$(IFS=,; printf '%s' "${consumers[*]}")"
} > "$temporary"
mv "$temporary" "$state_file"
printf '[PASS] ROTATION %s fixture verified every consumer before old-credential retirement (values redacted)\n' "$credential"
