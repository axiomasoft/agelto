#!/usr/bin/env bash
set -euo pipefail
umask 077

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf -- "$fixture"' EXIT
export FIXTURE_AGELTO="$fixture/agelto" FIXTURE_REMOTE="$fixture/remote"
backup_root="$FIXTURE_AGELTO/backups/local"
mkdir -p "$fixture/bin" "$fixture/etc" "$FIXTURE_REMOTE/11111111" "$FIXTURE_AGELTO/bin" \
    "$FIXTURE_AGELTO/repository/local/secrets" "$backup_root"/{20000101T000000Z,20000102T000000Z}
for name in .env.production docker-compose.override.yml .env.backup; do
    printf 'fixture\n' > "$FIXTURE_AGELTO/repository/$name"
done
for name in external-env backup-env-gate backup-local backup-local-enabled backup-local-watchdog backup-local-watchdog-enabled backup-objects.php; do
    printf 'fixture\n' > "$FIXTURE_AGELTO/bin/$name"
done
printf 'fixture\n' > "$FIXTURE_AGELTO/nginx-app.conf"
printf 'sftp:storage.example.test:/agelto\n' > "$fixture/etc/backup-restic-repository"
printf 'fixture\n' > "$fixture/etc/backup-restic-password"
printf 'fixture\n' > "$fixture/etc/backup-age-recipients"
printf 'offhost=1\nrestic_snapshot=11111111\n' > "$backup_root/20000101T000000Z/status"
printf 'offhost=0\n' > "$backup_root/20000102T000000Z/status"

# Only path roots are substituted. All Docker and remote I/O are sent to fixture executables.
sed -e "s|/srv/agelto|$FIXTURE_AGELTO|g" -e "s|/etc/agelto|$fixture/etc|g" \
    "$root/docker/host/backup/backup-local-enabled.sh" > "$fixture/payload"

cat > "$fixture/bin/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
case "$*" in
    'inspect --format '*com.docker.compose.project*) echo agelto_prod ;;
    'inspect --format '*'"/var/lib/postgresql"'*) echo agelto_prod_pgsql_data ;;
    'inspect --format '*io.agelto.backup.export*) cat "$FIXTURE_AGELTO/export-token" ;;
    'rm --force agelto_prod-backup-objects-'*) rm "$FIXTURE_AGELTO/export-token" ;;
    'cp agelto_prod-backup-objects-'*)
        objects=$(mktemp -d)
        trap 'rm -rf -- "$objects"' EXIT
        printf '[]\n' > "$objects/objects.json"
        tar -C "$objects" -cf - .
        ;;
    'container inspect '*) exit 1 ;;
    *'SELECT current_database(), current_user;'*) echo 'agelto|agelto' ;;
    *'SELECT pg_database_size'*) echo 100 ;;
    *'du -sk /data'*) printf '1\t/data\n' ;;
    *'test -d /var/www/html/storage'*) exit 1 ;;
    *'SELECT datname FROM pg_database'*) ;;
    *'SELECT '\''migration:'\'' '*) printf 'migration:fixture\ntable:users\n' ;;
    *'pg_dump '*) printf 'PGDMP fixture\n' ;;
    *'pg_restore '*) cat >/dev/null ;;
    *'createdb '*|*'dropdb '*) ;;
    *'SELECT current_database();'*) printf 'fixture_rehearsal\n1\n1\n' ;;
    *) printf 'unexpected Docker command in fixture\n' >&2; exit 91 ;;
esac
EOF
cat > "$FIXTURE_AGELTO/bin/compose" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ "$*" == *'run --no-deps -T --name '* && "$*" != *'--user root'* && "$*" == *'test "$(id -u)" -ne 0'* ]]
[[ $6 == --label && $7 == io.agelto.backup.export=* ]]
printf '%s\n' "${7#*=}" > "$FIXTURE_AGELTO/export-token"
echo 'fixture entrypoint startup message'
EOF
cat > "$fixture/bin/df" <<'EOF'
#!/bin/sh
printf 'header\nfixture 1 0 99999999 1%% /\n'
EOF
cat > "$fixture/bin/age" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ $# == 4 && $1 == -R && -s $2 && $3 == -o ]]
sha256sum > "$4"
EOF
cat > "$fixture/bin/jq" <<'EOF'
#!/usr/bin/env python3
import json
import sys

assert sys.argv[1:] == ["-er", 'select(.message_type == "summary") | .snapshot_id']
print(next(row["snapshot_id"] for row in map(json.loads, sys.stdin) if row["message_type"] == "summary"))
EOF
cat > "$fixture/bin/restic" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ $1 == --repository-file && $3 == --password-file && $5 == --no-cache && $6 == -o ]]
shift 7
case "$1 $2" in
    'cat config') ;;
    'cat snapshot') [[ -d "$FIXTURE_REMOTE/$3" ]] ;;
    'backup --json')
        mkdir -p "$FIXTURE_REMOTE/22222222"
        cp -a . "$FIXTURE_REMOTE/22222222/"
        printf '{"message_type":"summary","snapshot_id":"22222222"}\n'
        ;;
    'restore --verify')
        [[ $4 == --target ]]
        cp -a "$FIXTURE_REMOTE/$3/." "$5/"
        if [[ ${FIXTURE_CORRUPT:-0} == 1 ]]; then
            printf 'bad bytes\n' >> "$5/postgres.dump"
        fi
        ;;
    *) exit 92 ;;
esac
EOF
chmod 0700 "$fixture/bin/"* "$FIXTURE_AGELTO/bin/compose"
export PATH="$fixture/bin:$PATH" BACKUP_LOCAL_RETENTION=1

if FIXTURE_CORRUPT=1 bash "$fixture/payload"; then
    echo 'Повреждённая внешняя копия ошибочно признана проверенной' >&2
    exit 1
fi
[[ -d "$backup_root/20000101T000000Z" && ! -e "$backup_root/latest.status" ]]
[[ ! -e "$FIXTURE_AGELTO/export-token" ]]
echo 'Повреждённая внешняя копия сохраняет предыдущий локальный бэкап'

bash "$fixture/payload"
[[ ! -e "$backup_root/20000101T000000Z" && -d "$backup_root/20000102T000000Z" ]]
grep -qx 'restic_snapshot=22222222' "$backup_root/latest.status"
bash "$root/docker/scripts/backup-watchdog.sh" check \
    --status-file "$FIXTURE_AGELTO/backups/latest.status" --max-age 3600
[[ -s "$FIXTURE_REMOTE/22222222/config-secrets.tar.age" && -s "$FIXTURE_REMOTE/22222222/backup.manifest" ]]
echo 'Прочитанная внешняя копия проверяется до ротации, локальная копия без снимка сохраняется'
