---
audience: operator
type: runbook
status: living
---

# Автоматический локальный backup для manual deployment

Это проверенный host-only путь рядом с другими проектами. Он не использует fixture-only
`backup-production.sh apply`, не меняет соседние projects и не заявляет off-host DR readiness.
Факты первого исполнения находятся в root `DEPLOYMENT_REPORT.md`.

## Предусловия и границы

Нужны рабочие `agelto_prod`, точная DB/user `agelto`, container
`agelto_prod-postgres-1`, volume `agelto_prod_pgsql_data`, scoped Compose wrapper, external-env
adapter из [manual deploy](manual-deployment.md), Bash/flock/tar/OpenSSL/systemd.
Deploy уже имеет Docker access; sudo не добавляется. Root устанавливает только новые
`agelto-backup-local*` units, deploy запускает backup. Сначала проверяйте отсутствие файлов
и symlinks; существующую стороннюю job нельзя заменить по совпадению имени.

Backup берёт тот же `/srv/agelto/.release.lock`, что deploy/rollback, проверяет identity/volume,
disk floor (10 GiB + 3 × database bytes + 2 × MinIO volume bytes), делает custom-format dump
и восстанавливает его в **новую** timestamped `agelto_*_rehearsal` DB. Source schema/table names
и migration ledger сравниваются с restored target. Original DB не восстанавливается.

Экспорт S3/estimates_s3 — logical **current-state** object copy, не version history и не атомарный
снимок всей системы. Во время backup работающие users/workers могут менять objects/data.
Пустые buckets проверяют доступ и экспорт, но не доказывают восстановление реального набора
objects. Redis sessions/cache/queues не включены: recovery очередей требует согласованной
idempotent replay стратегии, нельзя обещать сохранение queued jobs.

Config/secrets archive зашифрован AES-256-CBC/PBKDF2 со случайной солью; key — отдельный
`/srv/agelto/backup-encryption.key` deploy:deploy 0600, вне archive. Эта схема не является AEAD
или защитой от злонамеренной подмены manifest: SHA256 здесь обнаруживает accidental corruption.
Остальные local dump/object files защищены directory 0700/files 0600, но не зашифрованы.
Key хранится на том же host: для DR нужна отдельная защищённая копия key и encrypted full backup
внешнему destination. GitHub private SSH key не входит в archive.

**Retention/cleanup не включены.** Все archives и rehearsal DB сохраняются согласно запрету
удаления. Оценивайте рост диска ежедневно; floor откажется создавать backup при недостатке места,
а не удалит чужие/старые ресурсы. Для реальных больших production данных сначала согласуйте
capacity/retention и isolated restore target; ежедневные retained copies расходуют диск.

## Установка скриптов

Создайте новые candidate files редактором с кодом ниже, проверьте Bash/PHP syntax, затем root
устанавливает их в `/srv/agelto/bin`. Не выводите secret files и не передавайте secrets в argv.

### backup-local

```bash
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
tar -C /srv/agelto -czf - repository/.env.production repository/docker-compose.override.yml \
    repository/local/secrets nginx-app.conf bin/compose bin/external-env | \
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
```

### backup-objects.php

```php
<?php
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$root = '/backup-export';
$manifest = [];
foreach (['s3', 'estimates_s3'] as $diskName) {
    $disk = Illuminate\Support\Facades\Storage::disk($diskName);
    foreach ($disk->allFiles() as $path) {
        if ($path === '' || str_starts_with($path, '/') || preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f\x7f]~', $path)) {
            throw new RuntimeException('Unsafe object path');
        }
        $destination = "$root/$diskName/$path";
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true)) {
            throw new RuntimeException('Cannot create export directory');
        }
        $source = $disk->readStream($path);
        $target = fopen($destination, 'xb');
        if (!is_resource($source) || !is_resource($target)) {
            throw new RuntimeException('Cannot open object streams');
        }
        if (stream_copy_to_stream($source, $target) === false) {
            throw new RuntimeException('Cannot copy object');
        }
        fclose($source);
        fclose($target);
        chmod($destination, 0600);
        $manifest[] = ['disk' => $diskName, 'path' => $path, 'bytes' => filesize($destination), 'sha256' => hash_file('sha256', $destination)];
    }
}
file_put_contents("$root/objects.json", json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
printf("Exported current-state objects: %d\n", count($manifest));
```

### backup-local-watchdog

```bash
#!/usr/bin/env bash
set -euo pipefail
readonly status_file=/srv/agelto/backups/local/latest.status
[[ -f $status_file && ! -L $status_file ]] || { echo 'Agelto local backup status is absent' >&2; exit 1; }
completed_at=$(awk -F= '$1 == "completed_at" {print $2}' "$status_file")
[[ $completed_at =~ ^[0-9]+$ ]]
grep -qx 'verified_local=1' "$status_file"
now=$(date +%s)
(( now >= completed_at && now - completed_at <= 129600 )) || { echo 'Agelto local backup is stale' >&2; exit 1; }
echo 'Agelto LOCAL backup is fresh; off-host disaster recovery is not configured'
```

От root после проверки candidate directory (подставьте свой absolute path):

```bash
agelto_candidates=/srv/agelto/operations/REPLACE_WITH_VERIFIED_STAMP/candidates
test ! -e /srv/agelto/bin/backup-local
test ! -e /srv/agelto/bin/backup-objects.php
test ! -e /srv/agelto/bin/backup-local-watchdog
bash -n "$agelto_candidates/backup-local" "$agelto_candidates/backup-local-watchdog"
# PHP syntax проверяется локально/в PHP image; host PHP устанавливать ради этого не нужно.
install -o deploy -g deploy -m 0755 "$agelto_candidates/backup-local" /srv/agelto/bin/backup-local
install -o deploy -g deploy -m 0755 "$agelto_candidates/backup-local-watchdog" /srv/agelto/bin/backup-local-watchdog
install -o deploy -g deploy -m 0644 "$agelto_candidates/backup-objects.php" /srv/agelto/bin/backup-objects.php
test ! -e /srv/agelto/backup-encryption.key
sudo -u deploy bash -c 'umask 077; openssl rand -base64 48 > /srv/agelto/backup-encryption.key'
test -f /srv/agelto/.release.lock || install -o deploy -g deploy -m 0600 /dev/null /srv/agelto/.release.lock
test ! -L /srv/agelto/.release.lock
chmod 0600 /srv/agelto/.release.lock
install -d -o deploy -g deploy -m 0700 /srv/agelto/backups/local
```

Key создаётся только если отсутствует, без содержимого в stdout. Не генерируйте новый key
поверх старого: это лишит доступа к existing encrypted archives. Повторная установка требует
protected snapshots существующих **Agelto** scripts/units и проверки diff.

## Systemd units

Сохраните эти candidate files рядом со скриптами:

### agelto-backup-local.service

```ini
[Unit]
Description=Agelto local PostgreSQL restore-verified and object current-state backup
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
User=deploy
Group=deploy
UMask=0077
WorkingDirectory=/srv/agelto/repository
ExecStart=/srv/agelto/bin/backup-local
TimeoutStartSec=2h
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=read-only
ReadWritePaths=/srv/agelto/backups /srv/agelto/.release.lock
```

### agelto-backup-local.timer

```ini
[Unit]
Description=Daily Agelto local backup (no off-host claim)

[Timer]
OnCalendar=*-*-* 02:00:00 UTC
RandomizedDelaySec=10m
Persistent=true
Unit=agelto-backup-local.service

[Install]
WantedBy=timers.target
```

### agelto-backup-local-watchdog.service

```ini
[Unit]
Description=Check Agelto local backup freshness

[Service]
Type=oneshot
User=deploy
Group=deploy
ExecStart=/srv/agelto/bin/backup-local-watchdog
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
```

### agelto-backup-local-watchdog.timer

```ini
[Unit]
Description=Hourly Agelto local backup freshness check

[Timer]
OnCalendar=hourly
RandomizedDelaySec=5m
Persistent=true
Unit=agelto-backup-local-watchdog.service

[Install]
WantedBy=timers.target
```

От root (Agelto units должны ещё отсутствовать):

```bash
set -euo pipefail
export SYSTEMD_PAGER=cat
for agelto_unit in agelto-backup-local.service agelto-backup-local.timer \
  agelto-backup-local-watchdog.service agelto-backup-local-watchdog.timer; do
  test ! -e "/etc/systemd/system/$agelto_unit"
  test ! -L "/etc/systemd/system/$agelto_unit"
done
for agelto_unit in agelto-backup-local.service agelto-backup-local.timer \
  agelto-backup-local-watchdog.service agelto-backup-local-watchdog.timer; do
  install -o root -g root -m 0644 "$agelto_candidates/$agelto_unit" "/etc/systemd/system/$agelto_unit"
done
systemd-analyze verify /etc/systemd/system/agelto-backup-local.service \
  /etc/systemd/system/agelto-backup-local.timer \
  /etc/systemd/system/agelto-backup-local-watchdog.service \
  /etc/systemd/system/agelto-backup-local-watchdog.timer
systemctl daemon-reload
systemctl start agelto-backup-local.service
systemctl show agelto-backup-local.service -p Result -p ExecMainStatus
sudo -u deploy /srv/agelto/bin/backup-local-watchdog
systemctl start agelto-backup-local-watchdog.service
systemctl show agelto-backup-local-watchdog.service -p Result -p ExecMainStatus
systemctl enable --now agelto-backup-local.timer agelto-backup-local-watchdog.timer
systemctl show agelto-backup-local.timer agelto-backup-local-watchdog.timer \
  -p ActiveState -p UnitFileState -p NextElapseUSecRealtime
```

Ожидается `Result=success`, `ExecMainStatus=0`; обе timers enabled/active, следующий запуск
daily backup в 02:00 UTC + random до 10 min, watchdog hourly + до 5 min. Oneshot после успеха
обычно inactive/dead: `systemctl status` exit 3 не означает failed backup. Используйте Result,
receipt и checksum, не только ActiveState. `Persistent=true` позволяет catch-up после downtime,
но не доказывает фактический запуск после reboot. Shared host **не перезагружается** ради теста.

Watchdog проверяет только local freshness 36 h и честно сообщает отсутствие off-host.
Не заменяйте им original off-host watchdog и не ставьте `offhost=1` без copy verification.
Failure виден в journal/systemd failed units; delivery во внешний alert endpoint пока не настроен.

## Проверка, логи и безопасный rollback automation

```bash
export SYSTEMD_PAGER=cat
systemctl show agelto-backup-local.service -p Result -p ExecMainStatus
systemctl list-timers --all --no-pager 'agelto-backup-local*'
journalctl -u agelto-backup-local.service -u agelto-backup-local-watchdog.service \
  --since today --no-pager
cat /srv/agelto/backups/local/latest.status
# Выберите точный backup_dir из status, не glob; это non-secret metadata.
backup_dir=/srv/agelto/backups/local/REPLACE_WITH_VERIFIED_STAMP
cat "$backup_dir/restore.receipt"
sudo -u deploy bash -c 'cd "$1"; sha256sum --check manifest.sha256' bash "$backup_dir"
```

Не выводите dump/config archive/secret key. Full restore в original DB, schema rollback и cleanup
не являются проверочными командами. Для остановки только автоматизации root выполняет:

```bash
systemctl disable --now agelto-backup-local.timer agelto-backup-local-watchdog.timer
```

Это не останавливает приложение/соседей и не удаляет backup/key/rehearsal DB/units.
Активную backup job дождитесь завершения; при ошибке расследуйте её journal, не удаляйте данные.

## Off-host completion gate

Нужны destination, credentials, encryption/recovery-key custody и согласованный capacity/retention.
Сначала encrypt **полный** set (не только config), copy вне сервера, сверить copied checksums
и выполнить isolated object/config/DB restore rehearsal. Только после этого возможны
`offhost=1`, off-host watchdog и подтверждённая DR readiness. Разовый download на рабочую станцию
не доказывает автоматическое off-host расписание. SMTP/Telegram/webhook alert credentials
и фактическая доставка проверяются отдельно.
