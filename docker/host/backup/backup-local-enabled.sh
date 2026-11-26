#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /srv/agelto/repository
readonly backup_root=/srv/agelto/backups/local
readonly source_container=agelto_prod-postgres-1
readonly expected_volume=agelto_prod_pgsql_data
readonly age_recipients=/etc/agelto/backup-age-recipients
readonly repository_file=/etc/agelto/backup-restic-repository
readonly password_file=/etc/agelto/backup-restic-password

fail() { printf 'Agelto backup: %s\n' "$1" >&2; exit 1; }
for path in "$age_recipients" "$repository_file" "$password_file"; do
    [[ -f $path && ! -L $path && -s $path && -r $path ]] || fail "missing or unsafe backup configuration: $path"
done
for path in "$repository_file" "$password_file"; do
    permissions=$(stat -c '%a' "$path")
    (( (8#$permissions & 077) == 0 )) || fail 'Restic configuration files must be owner-only'
done
repository=$(<"$repository_file")
[[ $repository =~ ^sftp:[a-zA-Z0-9][a-zA-Z0-9._@-]*:/[a-zA-Z0-9._/-]+$ && $repository != *'/../'* && $repository != */.. ]] \
    || fail 'Restic repository must be a remote SFTP destination'
remote_host=${repository#sftp:}
remote_host=${remote_host%%:*}
remote_host=${remote_host##*@}
[[ ${remote_host,,} != localhost && $remote_host != 127.* ]] || fail 'Restic repository must be off-host'
restic_command=(restic --repository-file "$repository_file" --password-file "$password_file" --no-cache
    -o 'sftp.args=-oBatchMode=yes -oStrictHostKeyChecking=yes')
retention=${BACKUP_LOCAL_RETENTION:-7}
[[ $retention =~ ^[1-9][0-9]{0,5}$ ]] || fail 'local retention must be a positive count'
[[ ! -L /srv/agelto/.release.lock ]]
exec 9>>/srv/agelto/.release.lock
flock -n 9 || { echo 'Agelto release/backup lock is held' >&2; exit 1; }
[[ $(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$source_container") == agelto_prod ]]
[[ $(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Name}}{{end}}{{end}}' "$source_container") == "$expected_volume" ]]
[[ $(docker exec "$source_container" psql -U agelto -d agelto -Atc 'SELECT current_database(), current_user;' </dev/null) == 'agelto|agelto' ]]
[[ ! -L "${backup_root%/*}" && ! -L $backup_root ]]
"${restic_command[@]}" cat config </dev/null >/dev/null 2>&1 || fail 'cannot open the authenticated off-host repository'
available_kib=$(df -Pk /srv/agelto | awk 'NR == 2 {print $4}')
database_bytes=$(docker exec "$source_container" psql -U agelto -d agelto -Atc "SELECT pg_database_size('agelto');" </dev/null)
object_kib=$(docker exec agelto_prod-minio-1 du -sk /data </dev/null | awk '{print $1}')
receipt_kib=0
if docker exec agelto_prod-app-1 test -d /var/www/html/storage/app/private/llm/receipts </dev/null 2>/dev/null; then
    receipt_kib=$(docker exec agelto_prod-app-1 du -sk /var/www/html/storage/app/private/llm/receipts </dev/null | awk '{print $1}')
fi
[[ $available_kib =~ ^[0-9]+$ && $database_bytes =~ ^[0-9]+$ && $object_kib =~ ^[0-9]+$ && $receipt_kib =~ ^[0-9]+$ ]]
required_kib=$((10 * 1024 * 1024 + 3 * database_bytes / 1024 + 2 * object_kib + 2 * receipt_kib))
(( available_kib > required_kib )) || { echo 'Agelto backup disk floor failed' >&2; exit 1; }
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_dir="$backup_root/$stamp"
rehearsal_db="agelto_${stamp,,}_rehearsal"
[[ $rehearsal_db =~ ^agelto_[0-9]{8}t[0-9]{6}z_rehearsal$ ]]
[[ ! -e $backup_dir ]]
[[ -z $(docker exec "$source_container" psql -U agelto -d agelto -Atc "SELECT datname FROM pg_database WHERE datname = '$rehearsal_db';" </dev/null) ]]
mkdir -p -m 0700 "$backup_root"
staging=$(mktemp -d "$backup_root/.incomplete-$stamp.XXXXXX")
export_token=${staging##*.}
export_container="agelto_prod-backup-objects-$stamp-$export_token"
rehearsal_created=false
verification_dir=''
status_staging=''
remove_export_container() {
    if [[ $(docker inspect --format '{{index .Config.Labels "io.agelto.backup.export"}}' "$export_container" 2>/dev/null) == "$export_token" ]]; then
        docker rm --force "$export_container" >/dev/null
    fi
}
cleanup() {
    remove_export_container || printf 'Agelto backup: could not remove the export container\n' >&2
    if "$rehearsal_created"; then
        docker exec "$source_container" dropdb -U agelto --if-exists --force "$rehearsal_db" </dev/null >/dev/null 2>&1 \
            || printf 'Agelto backup: could not remove the rehearsal database\n' >&2
    fi
    for temporary in "$staging" "$verification_dir" "$status_staging"; do
        if [[ -n $temporary && -e $temporary ]]; then
            rm -rf -- "$temporary"
        fi
    done
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
mkdir -m 0700 "$staging/objects"
schema_query="SELECT 'migration:' || migration FROM migrations UNION ALL SELECT 'table:' || tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1;"
docker exec "$source_container" psql -U agelto -d agelto -Atc "$schema_query" </dev/null > "$staging/schema.source"
docker exec "$source_container" pg_dump -U agelto -d agelto -Fc </dev/null > "$staging/postgres.dump"
[[ -s $staging/postgres.dump ]]
docker exec "$source_container" createdb -U agelto -O agelto "$rehearsal_db" </dev/null
rehearsal_created=true
docker exec -i "$source_container" pg_restore -U agelto -d "$rehearsal_db" --no-owner --no-acl --exit-on-error < "$staging/postgres.dump"
docker exec "$source_container" psql -U agelto -d "$rehearsal_db" -Atc "$schema_query" </dev/null > "$staging/schema.restored"
cmp "$staging/schema.source" "$staging/schema.restored"
docker exec "$source_container" psql -U agelto -d "$rehearsal_db" -Atc 'SELECT current_database(); SELECT count(*) FROM migrations; SELECT count(*) FROM users;' </dev/null > "$staging/restore.receipt"
docker exec "$source_container" dropdb -U agelto --if-exists --force "$rehearsal_db" </dev/null
rehearsal_created=false

# The app entrypoint reads file secrets before dropping to www-data. Copy the completed
# export separately so container startup output cannot be mixed with archive bytes.
/srv/agelto/bin/compose run --no-deps -T --name "$export_container" --label "io.agelto.backup.export=$export_token" \
    --volume /srv/agelto/bin/backup-objects.php:/backup-objects.php:ro \
    app sh -ec '
        test "$(id -u)" -ne 0
        umask 077
        export AGELTO_BACKUP_EXPORT_ROOT=/tmp/agelto-backup-export
        mkdir -m 0700 "$AGELTO_BACKUP_EXPORT_ROOT"
        php /backup-objects.php
    ' </dev/null
docker cp "$export_container:/tmp/agelto-backup-export/." - |
    tar -C "$staging/objects" --no-same-owner --no-same-permissions -xf -
[[ -s $staging/objects/objects.json ]] || fail 'object export manifest is missing'
remove_export_container

if docker container inspect agelto_mail-smtp-1 >/dev/null 2>&1; then
    [[ $(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' agelto_mail-smtp-1) == agelto_mail ]]
    [[ $(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/etc/opendkim/keys"}}{{.Name}}{{end}}{{end}}' agelto_mail-smtp-1) == agelto_mail_dkim_keys ]]
    [[ -d /srv/agelto/mail && ! -L /srv/agelto/mail ]]
    smtp_image=$(docker inspect --format '{{.Config.Image}}' agelto_mail-smtp-1)
    [[ $smtp_image =~ ^boky/postfix:5\.1\.0@sha256:[0-9a-f]{64}$ ]]
    docker run --rm --pull=never --network none --entrypoint tar \
        --volume agelto_mail_dkim_keys:/dkim:ro "$smtp_image" \
        -C /dkim -czf - . </dev/null | \
        age -R "$age_recipients" -o "$staging/smtp-dkim.tar.age"
    tar -C /srv/agelto -czf - mail | \
        age -R "$age_recipients" -o "$staging/smtp-config.tar.age"
fi
tar -C /srv/agelto -czf - repository/.env.production repository/docker-compose.override.yml \
    repository/.env.backup repository/local/secrets nginx-app.conf bin/compose bin/external-env \
    bin/backup-env-gate bin/backup-local bin/backup-local-enabled \
    bin/backup-local-watchdog bin/backup-local-watchdog-enabled bin/backup-objects.php | \
    age -R "$age_recipients" -o "$staging/config-secrets.tar.age"
[[ -s $staging/config-secrets.tar.age ]] || fail 'encrypted configuration archive is missing'
(
    cd "$staging"
    find . -type f ! -name manifest.sha256 ! -name status -print0 | sort -z | xargs -0 sha256sum > manifest.sha256
    sha256sum --check manifest.sha256 >/dev/null
)
manifest_hash=$(sha256sum "$staging/manifest.sha256" | awk '{print $1}')
printf 'schema=agelto.backup-manifest/v1\nverified=1\nmanifest_sha256=%s\nsource_backup_id=backup-%s\n' \
    "$manifest_hash" "$stamp" > "$staging/backup.manifest"
snapshot=$(
    cd "$staging"
    "${restic_command[@]}" backup --json --tag agelto-prod . </dev/null |
        jq -er 'select(.message_type == "summary") | .snapshot_id'
) || fail 'off-host backup upload failed'
[[ $snapshot =~ ^[0-9a-f]{8,64}$ ]] || fail 'invalid off-host snapshot ID'

verification_dir=$(mktemp -d "$backup_root/.verify-$stamp.XXXXXX")
"${restic_command[@]}" restore --verify "$snapshot" --target "$verification_dir" </dev/null >/dev/null \
    || fail 'off-host snapshot could not be read back and verified'
cmp -s "$staging/manifest.sha256" "$verification_dir/manifest.sha256" || fail 'off-host manifest differs'
cmp -s "$staging/backup.manifest" "$verification_dir/backup.manifest" || fail 'off-host backup metadata differs'
(
    cd "$verification_dir"
    sha256sum --check --strict manifest.sha256 >/dev/null
) || fail 'off-host file checksums differ'

completed_at=$(date +%s)
printf 'completed_at=%s\nverified=1\nverified_local=1\noffhost=1\nmanifest_sha256=%s\nbackup_dir=%s\nrehearsal_db=%s\nrestic_snapshot=%s\n' \
    "$completed_at" "$manifest_hash" "$backup_dir" "$rehearsal_db" "$snapshot" > "$staging/status"
mv -T -- "$staging" "$backup_dir"
for directory in "$backup_root" "${backup_root%/*}"; do
    status_staging=$(mktemp "$directory/.latest.XXXXXX")
    cat "$backup_dir/status" > "$status_staging"
    mv -T -- "$status_staging" "$directory/latest.status"
done
mapfile -t old_local_backups < <(find "$backup_root" -mindepth 1 -maxdepth 1 -type d -name '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z' -printf '%f\n' | sort -r)
for ((idx=retention; idx<${#old_local_backups[@]}; idx++)); do
    candidate="$backup_root/${old_local_backups[$idx]}"
    status="$candidate/status"
    [[ -f $status && ! -L $status ]] && grep -qx 'offhost=1' "$status" || continue
    old_snapshot=$(sed -n 's/^restic_snapshot=//p' "$status")
    if [[ $old_snapshot =~ ^[0-9a-f]{8,64}$ ]] && "${restic_command[@]}" cat snapshot "$old_snapshot" </dev/null >/dev/null 2>&1; then
        find "$candidate" -depth -delete
    fi
done
printf 'Agelto backup verified locally and off-host: %s; Restic snapshot: %s\n' "$backup_dir" "$snapshot"
