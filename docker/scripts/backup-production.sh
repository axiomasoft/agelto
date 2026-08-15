#!/usr/bin/env bash

set -euo pipefail

readonly script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly profile_checker="$script_dir/production-profile-check.sh"

fail() {
    printf '[FAIL] BACKUP %s\n' "$*" >&2
    exit 1
}

usage() {
    cat >&2 <<'EOF'
Usage:
  backup-production.sh check
  backup-production.sh fixture --fixture-root /tmp/path --profile /absolute/profile --adapter /absolute/fake-tool [--backup-id fixture-N] [--retention N]

The production apply path is intentionally owned by the follow-up operator rehearsal plan.
EOF
}

mode=${1:-}
[[ $mode == check || $mode == fixture ]] || {
    [[ $mode != apply ]] || fail 'apply requires the follow-up operator rehearsal authority'
    usage
    exit 2
}
shift

fixture_root=''
profile=''
adapter=''
backup_id='fixture-0001'
retention=2

while (($#)); do
    case "$1" in
        --fixture-root) fixture_root=${2:-}; shift ;;
        --profile) profile=${2:-}; shift ;;
        --adapter) adapter=${2:-}; shift ;;
        --backup-id) backup_id=${2:-}; shift ;;
        --retention) retention=${2:-}; shift ;;
        *) usage; exit 2 ;;
    esac
    shift
done

if [[ $mode == check ]]; then
    [[ -z $fixture_root && -z $profile && -z $adapter ]] || { usage; exit 2; }
    printf '%s\n' \
        lock \
        profile \
        disk-capacity \
        postgres-custom-dump \
        isolated-restore-verification \
        object-current-state-export \
        config-secret-archive \
        manifest-sha256 \
        encrypted-offhost-copy \
        verified-copy-before-prune
    exit 0
fi

[[ $fixture_root = /* && $profile = /* && $adapter = /* ]] || { usage; exit 2; }
[[ -x $adapter && -f $adapter && ! -L $adapter ]] || fail 'fixture adapter must be an executable regular file'
[[ $backup_id =~ ^fixture-[0-9]{4}$ ]] || fail 'fixture backup ID must match fixture-NNNN'
[[ $retention =~ ^[1-9][0-9]*$ ]] || fail 'retention must be positive'

tmp_base=$(realpath -m "${TMPDIR:-/tmp}")
resolved_root=$(realpath -m "$fixture_root")
[[ $resolved_root == "$tmp_base"/* && ! -L $fixture_root ]] || fail 'fixture root must be a non-symlink path below TMPDIR'

bash "$profile_checker" check --profile "$profile" >/dev/null
mkdir -p "$resolved_root/staging" "$resolved_root/local" "$resolved_root/offhost"
exec 9>"$resolved_root/.backup.lock"
flock -n 9 || fail 'another backup/release/recovery operation holds the lock'

staging="$resolved_root/staging/$backup_id"
local_copy="$resolved_root/local/$backup_id"
offhost_copy="$resolved_root/offhost/$backup_id"
[[ ! -e $staging && ! -e $local_copy && ! -e $offhost_copy ]] || fail 'backup ID already exists'
mkdir -m 0700 "$staging"

"$adapter" postgres-custom-dump "$staging/postgres.dump"
"$adapter" isolated-restore-verification "$staging/postgres.dump"
"$adapter" object-current-state-export "$staging/object-current.manifest"
"$adapter" config-secret-archive "$staging/config-secrets.tar.enc"

for artifact in postgres.dump object-current.manifest config-secrets.tar.enc; do
    [[ -s $staging/$artifact && ! -L $staging/$artifact ]] || fail "adapter did not create $artifact"
done

(
    cd "$staging"
    sha256sum postgres.dump object-current.manifest config-secrets.tar.enc > manifest.sha256
    sha256sum --check manifest.sha256 >/dev/null
)
printf 'backup_id=%s\nverified=1\n' "$backup_id" > "$staging/status"
mv "$staging" "$local_copy"
cp -a "$local_copy" "$offhost_copy"
diff -qr "$local_copy" "$offhost_copy" >/dev/null || fail 'off-host fixture copy differs from the verified local copy'
printf 'offhost=1\n' >> "$local_copy/status"
printf 'offhost=1\n' >> "$offhost_copy/status"

mapfile -t old_backups < <(find "$resolved_root/local" -mindepth 1 -maxdepth 1 -type d -name 'fixture-[0-9][0-9][0-9][0-9]' -printf '%f\n' | sort -r)
for ((index=retention; index<${#old_backups[@]}; index++)); do
    candidate=${old_backups[$index]}
    [[ -f $resolved_root/offhost/$candidate/status ]] || fail "refusing to prune $candidate before a verified off-host copy"
    grep -qx 'offhost=1' "$resolved_root/offhost/$candidate/status" || fail "refusing to prune unverified $candidate"
    find "$resolved_root/local/$candidate" -depth -delete
done

printf '[PASS] BACKUP %s verified locally and copied before retention pruning\n' "$backup_id"
