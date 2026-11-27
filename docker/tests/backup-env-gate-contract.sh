#!/usr/bin/env bash
# Hermetic: no Docker, production databases, or external requests.
set -euo pipefail
root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
fixture_dir=$(mktemp -d)
gate="$root_dir/docker/host/backup/backup-env-gate.sh"
# Fixtures are retained; no recursive cleanup.
printf 'BACKUP_ENABLED=false\n' > "$fixture_dir/disabled.env"
printf '# comment\n\nBACKUP_ENABLED=true\n' > "$fixture_dir/enabled.env"
printf 'BACKUP_ENABLED=true\nBACKUP_ENABLED=false\n' > "$fixture_dir/duplicate.env"
printf 'BACKUP_ENABLED=$(touch SHOULD_NOT_EXIST)\n' > "$fixture_dir/injection.env"
printf 'BACKUP_ENABLED=yes\n' > "$fixture_dir/invalid.env"
printf '' > "$fixture_dir/empty.env"
ln -s "$fixture_dir/enabled.env" "$fixture_dir/symlink.env"
bash "$gate" "$fixture_dir/disabled.env" -- bash -c 'exit 77'
[[ $(bash "$gate" "$fixture_dir/enabled.env" -- printf 'PAYLOAD_RAN') == PAYLOAD_RAN ]]
for profile in duplicate injection invalid empty symlink missing; do
    if bash "$gate" "$fixture_dir/$profile.env" -- printf 'UNEXPECTED_EXEC' > "$fixture_dir/result" 2>&1; then
        printf 'Expected rejection: %s\n' "$profile" >&2
        exit 1
    fi
    ! grep -q UNEXPECTED_EXEC "$fixture_dir/result"
done
if bash "$gate" "$fixture_dir/enabled.env" -- bash -c 'exit 23'; then
    exit 1
else
    [[ $? == 23 ]]
fi
printf 'Backup env gate contract passed (fixtures retained: %s)\n' "$fixture_dir"
