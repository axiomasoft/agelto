#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /srv/agelto/repository
readonly backup_root=/srv/agelto/backups/local
readonly source_container=agelto_prod-postgres-1
readonly expected_volume=agelto_prod_pgsql_data
[[ ! -L /srv/agelto/.release.lock ]]
exec 9>/srv/agelto/.release.lock
flock -n 9 || { echo 'Agelto release/backup lock is held' >&2; exit 1; }
[[ $(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$source_container") == agelto_prod ]]
[[ $(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Name}}{{end}}{{end}}' "$source_container") == "$expected_volume" ]]
[[ $(docker exec "$source_container" psql -U agelto -d agelto -Atc 'SELECT current_database(), current_user;' </dev/null) == 'agelto|agelto' ]]
[[ ! -L $backup_root && ! -L /srv/agelto/backup-encryption.key ]]
[[ -s /srv/agelto/backup-encryption.key ]]
[[ $(stat -c '%a' /srv/agelto/backup-encryption.key) == 600 ]]
available_kib=$(df -Pk /srv/agelto | awk 'NR == 2 {print $4}')
database_bytes=$(docker exec "$source_container" psql -U agelto -d agelto -Atc "SELECT pg_database_size('agelto');" </dev/null)
object_kib=$(docker exec agelto_prod-minio-1 du -sk /data </dev/null | awk '{print $1}')
[[ $available_kib =~ ^[0-9]+$ && $database_bytes =~ ^[0-9]+$ && $object_kib =~ ^[0-9]+$ ]]
required_kib=$((10 * 1024 * 1024 + 3 * database_bytes / 1024 + 2 * object_kib))
(( available_kib > required_kib )) || { echo 'Agelto backup disk floor failed' >&2; exit 1; }
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_dir="$backup_root/$stamp"
rehearsal_db="agelto_${stamp,,}_rehearsal"
[[ $rehearsal_db =~ ^agelto_[0-9]{8}t[0-9]{6}z_rehearsal$ ]]
[[ ! -e $backup_dir ]]
[[ -z $(docker exec "$source_container" psql -U agelto -d agelto -Atc "SELECT datname FROM pg_database WHERE datname = '$rehearsal_db';" </dev/null) ]]
mkdir -p -m 0700 "$backup_dir/objects"
schema_query="SELECT 'migration:' || migration FROM migrations UNION ALL SELECT 'table:' || tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1;"
docker exec "$source_container" psql -U agelto -d agelto -Atc "$schema_query" </dev/null > "$backup_dir/schema.source"
docker exec "$source_container" pg_dump -U agelto -d agelto -Fc </dev/null > "$backup_dir/postgres.dump"
[[ -s $backup_dir/postgres.dump ]]
docker exec "$source_container" createdb -U agelto -O agelto "$rehearsal_db" </dev/null
docker exec -i "$source_container" pg_restore -U agelto -d "$rehearsal_db" --no-owner --no-acl --exit-on-error < "$backup_dir/postgres.dump"
docker exec "$source_container" psql -U agelto -d "$rehearsal_db" -Atc "$schema_query" </dev/null > "$backup_dir/schema.restored"
cmp "$backup_dir/schema.source" "$backup_dir/schema.restored"
docker exec "$source_container" psql -U agelto -d "$rehearsal_db" -Atc 'SELECT current_database(); SELECT count(*) FROM migrations; SELECT count(*) FROM users;' </dev/null > "$backup_dir/restore.receipt"
/srv/agelto/bin/compose run --rm --no-deps -T --user root \
    --volume "$backup_dir/objects:/backup-export" \
    --volume /srv/agelto/bin/backup-objects.php:/backup-objects.php:ro \
    app php /backup-objects.php </dev/null
if docker container inspect agelto_mail-smtp-1 >/dev/null 2>&1; then
    [[ $(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' agelto_mail-smtp-1) == agelto_mail ]]
    [[ $(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/etc/opendkim/keys"}}{{.Name}}{{end}}{{end}}' agelto_mail-smtp-1) == agelto_mail_dkim_keys ]]
    [[ -d /srv/agelto/mail && ! -L /srv/agelto/mail ]]
    smtp_image=$(docker inspect --format '{{.Config.Image}}' agelto_mail-smtp-1)
    [[ $smtp_image =~ ^boky/postfix:5\.1\.0@sha256:[0-9a-f]{64}$ ]]
    docker run --rm --pull=never --network none --entrypoint tar \
        --volume agelto_mail_dkim_keys:/dkim:ro "$smtp_image" \
        -C /dkim -czf - . </dev/null | \
        openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:/srv/agelto/backup-encryption.key \
        -out "$backup_dir/smtp-dkim.tar.enc"
    openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/srv/agelto/backup-encryption.key \
        -in "$backup_dir/smtp-dkim.tar.enc" | tar -tzf - >/dev/null
    tar -C /srv/agelto -czf - mail | \
        openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:/srv/agelto/backup-encryption.key \
        -out "$backup_dir/smtp-config.tar.enc"
    openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/srv/agelto/backup-encryption.key \
        -in "$backup_dir/smtp-config.tar.enc" | tar -tzf - >/dev/null
fi
tar -C /srv/agelto -czf - repository/.env.production repository/docker-compose.override.yml \
    repository/.env.backup repository/local/secrets nginx-app.conf bin/compose bin/external-env \
    bin/backup-env-gate bin/backup-local bin/backup-local-enabled \
    bin/backup-local-watchdog bin/backup-local-watchdog-enabled bin/backup-objects.php | \
    openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:/srv/agelto/backup-encryption.key \
    -out "$backup_dir/config-secrets.tar.enc"
openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/srv/agelto/backup-encryption.key \
    -in "$backup_dir/config-secrets.tar.enc" | tar -tzf - >/dev/null
(
    cd "$backup_dir"
    find . -type f ! -name manifest.sha256 ! -name status -print0 | sort -z | xargs -0 sha256sum > manifest.sha256
    sha256sum --check manifest.sha256 >/dev/null
)
manifest_hash=$(sha256sum "$backup_dir/manifest.sha256" | awk '{print $1}')
completed_at=$(date +%s)
printf 'completed_at=%s\nverified_local=1\noffhost=0\nmanifest_sha256=%s\nbackup_dir=%s\nrehearsal_db=%s\n' \
    "$completed_at" "$manifest_hash" "$backup_dir" "$rehearsal_db" > "$backup_dir/status"
install -m 0600 "$backup_dir/status" "$backup_root/latest.status"
printf 'Agelto LOCAL backup verified: %s; retained rehearsal DB: %s; offhost=0\n' "$backup_dir" "$rehearsal_db"
