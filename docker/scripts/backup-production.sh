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
  backup-production.sh apply --profile /absolute/profile --authority-file /etc/agelto/operator-rehearsal-authority
  backup-production.sh fixture --fixture-root /tmp/path --profile /absolute/profile --adapter /absolute/fake-tool [--backup-id fixture-N] [--retention N]

EOF
}

mode=${1:-}
if [[ $mode == apply ]]; then
    [[ $# -eq 5 && $2 == --profile && $3 = /* && $4 == --authority-file \
        && $5 == /etc/agelto/operator-rehearsal-authority && -s $5 && -f $5 && ! -L $5 ]] \
        || fail 'apply requires the operator rehearsal authority'
    read -r owner permissions < <(stat -c '%u %a' "$5")
    [[ $owner == 0 ]] && (( (8#$permissions & 022) == 0 )) || fail 'operator authority must be controlled by root'
    [[ $(id -un) == deploy ]] || fail 'apply must run as deploy'
    bash "$profile_checker" check --profile "$3" >/dev/null
    exec /srv/agelto/bin/backup-local
fi
[[ $mode == check || $mode == fixture ]] || {
    usage
    exit 2
}
shift

fixture_root=''
profile=''
adapter=''
lock_file=''
backup_id='fixture-0001'
retention=2

while (($#)); do
    case "$1" in
        --fixture-root) fixture_root=${2:-}; shift ;;
        --profile) profile=${2:-}; shift ;;
        --adapter) adapter=${2:-}; shift ;;
        --lock-file) lock_file=${2:-}; shift ;;
        --backup-id) backup_id=${2:-}; shift ;;
        --retention) retention=${2:-}; shift ;;
        *) usage; exit 2 ;;
    esac
    shift
done

if [[ $mode == check ]]; then
    [[ -z $fixture_root && -z $profile && -z $adapter && -z $lock_file ]] || { usage; exit 2; }
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
[[ $backup_id =~ ^(fixture-[0-9]{4}|backup-[0-9]{8}T[0-9]{6}Z)$ ]] || fail 'fixture backup ID must match fixture-NNNN or backup-YYYYMMDDTHHMMSSZ'
[[ $retention =~ ^[1-9][0-9]*$ ]] || fail 'retention must be positive'

tmp_base=$(realpath -m "${TMPDIR:-/tmp}")
resolved_root=$(realpath -m "$fixture_root")
[[ $resolved_root == "$tmp_base"/* && ! -L $fixture_root ]] || fail 'fixture root must be a non-symlink path below TMPDIR'
if [[ -z $lock_file ]]; then
    lock_file="$resolved_root/.backup.lock"
fi
[[ $lock_file = /* && ! -L $lock_file && $(realpath -m "$lock_file") == "$tmp_base"/* ]] || fail 'lock file must be an absolute non-symlink path below TMPDIR'

bash "$profile_checker" check --profile "$profile" >/dev/null
mkdir -p "$resolved_root/staging" "$resolved_root/local" "$resolved_root/offhost" "$(dirname "$lock_file")"
exec 9>"$lock_file"
flock -n 9 || fail 'another backup/release/recovery operation holds the lock'

staging="$resolved_root/staging/$backup_id"
local_copy="$resolved_root/local/$backup_id"
offhost_copy="$resolved_root/offhost/$backup_id"
offhost_staging="$resolved_root/offhost/.staging-$backup_id.$$"
[[ ! -e $staging && ! -e $local_copy && ! -e $offhost_copy && ! -e $offhost_staging ]] || fail 'backup ID already exists'
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
manifest_sha=$(sha256sum "$staging/manifest.sha256" | cut -d' ' -f1)
completed_at=$(date +%s)
cat > "$staging/backup.manifest" <<EOF
schema=agelto.backup-manifest/v1
verified=1
manifest_sha256=$manifest_sha
source_backup_id=$backup_id
EOF
cat > "$staging/status" <<EOF
completed_at=$completed_at
verified=1
offhost=0
manifest_sha256=$manifest_sha
EOF
mv "$staging" "$local_copy"
cp -a "$local_copy" "$offhost_staging"
diff -qr "$local_copy" "$offhost_staging" >/dev/null || fail 'off-host fixture copy differs from the verified local copy'
sed -i 's/^offhost=0$/offhost=1/' "$offhost_staging/status"
mv "$offhost_staging" "$offhost_copy"
sed -i 's/^offhost=0$/offhost=1/' "$local_copy/status"
cp "$offhost_copy/status" "$resolved_root/latest.status"

mapfile -t old_backups < <(find "$resolved_root/local" -mindepth 1 -maxdepth 1 -type d \( -name 'fixture-[0-9][0-9][0-9][0-9]' -o -name 'backup-[0-9]*T[0-9]*Z' \) -printf '%f\n' | sort -r)
for ((index=retention; index<${#old_backups[@]}; index++)); do
    candidate=${old_backups[$index]}
    [[ -f $resolved_root/offhost/$candidate/status ]] || fail "refusing to prune $candidate before a verified off-host copy"
    grep -qx 'offhost=1' "$resolved_root/offhost/$candidate/status" || fail "refusing to prune unverified $candidate"
    find "$resolved_root/local/$candidate" -depth -delete
done

printf '[PASS] BACKUP %s verified locally and copied before retention pruning\n' "$backup_id"
