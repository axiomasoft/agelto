---
audience: operator
type: runbook
status: living
---

# Автоматический локальный backup для manual deployment

Это проверенный host-only путь рядом с другими проектами. Он не использует fixture-only
`backup-production.sh apply`, не меняет соседние projects и не заявляет off-host DR readiness.
Факты первого исполнения находятся в root `DEPLOYMENT_REPORT.md`.
По текущему решению владельца feature установлен, но **выключен** через
`/srv/agelto/repository/.env.backup`: `BACKUP_ENABLED=false`.
Enabled timers лишь вызывают launcher: при false не выполняются dump/restore/export и
freshness watchdog, не обновляется latest receipt. Приложение не зависит от backup flag.

## Предусловия и границы

Нужны рабочие `agelto_prod`, точная DB/user `agelto`, container
`agelto_prod-postgres-1`, volume `agelto_prod_pgsql_data`, scoped Compose wrapper, external-env
adapter из [manual deploy](manual-deployment.md), Bash/flock/tar/OpenSSL/systemd.
Deploy уже имеет Docker access; sudo не добавляется. Root устанавливает только новые
`agelto-backup-local*` units, deploy запускает backup. Сначала проверяйте отсутствие файлов
и symlinks; существующую стороннюю job нельзя заменить по совпадению имени.

При `BACKUP_ENABLED=true` backup берёт тот же `/srv/agelto/.release.lock`, что deploy/rollback, проверяет identity/volume,
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
Если существует отдельный `agelto_mail-smtp-1`, payload сначала проверяет project/volume/image
identity и отдельно шифрует DKIM volume и SMTP config. Private DKIM key не выводится.
Это read-only archive/list validation, не обещание полного SMTP/objects DR restore.

**Retention/cleanup не включены.** Все archives и rehearsal DB сохраняются согласно запрету
удаления. Оценивайте рост диска ежедневно; floor откажется создавать backup при недостатке места,
а не удалит чужие/старые ресурсы. Для реальных больших production данных сначала согласуйте
capacity/retention и isolated restore target; ежедневные retained copies расходуют диск.

## Установка скриптов

Sources теперь находятся в `docker/host/backup/` и проверяются как обычные scripts.
Не выводите secret files и не передавайте secrets в argv.

### backup-local

Канонический payload source: `docker/host/backup/backup-local-enabled.sh`.
Public launcher source: `docker/host/backup/backup-local.sh`. Он использует общий
`backup-env-gate.sh`: env не source-ится, допустимы только один BACKUP_ENABLED=true/false,
comments и пустые строки; missing/invalid/duplicate/symlink config отказывает до payload.

### backup-objects.php

Канонический exporter source: `docker/host/backup/backup-objects.php`.

### backup-local-watchdog

Канонический payload source: `docker/host/backup/backup-local-watchdog-enabled.sh`.
Public launcher source: `docker/host/backup/backup-local-watchdog.sh`. При false watchdog
тоже пропускается без stale failure. Не обходите launcher при обычной эксплуатации.

От root для новой установки (checkout и named services должны уже быть проверены):

```bash
agelto_candidates=/srv/agelto/operations/REPLACE_WITH_VERIFIED_STAMP/candidates
agelto_backup_sources=/srv/agelto/repository/docker/host/backup
for agelto_backup_file in backup-local backup-local-enabled backup-local-watchdog \
  backup-local-watchdog-enabled backup-env-gate backup-objects.php; do
  test ! -e "/srv/agelto/bin/$agelto_backup_file"
  test ! -L "/srv/agelto/bin/$agelto_backup_file"
done
test ! -e /srv/agelto/repository/.env.backup
for agelto_backup_script in "$agelto_backup_sources"/*.sh; do
  bash -n "$agelto_backup_script"
done
# PHP syntax проверяется локально/в PHP image; host PHP устанавливать ради этого не нужно.
for agelto_backup_file in backup-local backup-local-enabled backup-local-watchdog \
  backup-local-watchdog-enabled backup-env-gate; do
  install -o deploy -g deploy -m 0755 "$agelto_backup_sources/$agelto_backup_file.sh" "/srv/agelto/bin/$agelto_backup_file"
done
install -o deploy -g deploy -m 0644 "$agelto_backup_sources/backup-objects.php" /srv/agelto/bin/backup-objects.php
install -o deploy -g deploy -m 0600 "$agelto_backup_sources/backup.env.example" /srv/agelto/repository/.env.backup
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

При default false ожидается `Result=success`, `ExecMainStatus=0` и disabled message в journal,
**не** новый backup. Обе timers enabled/active, следующий launcher запуск
daily backup в 02:00 UTC + random до 10 min, watchdog hourly + до 5 min. Oneshot после успеха
обычно inactive/dead: `systemctl status` exit 3 не означает failed backup. Используйте Result,
receipt и checksum, не только ActiveState; при false прежний receipt — исторический.
`Persistent=true` позволяет catch-up после downtime,
но не доказывает фактический запуск после reboot. Shared host **не перезагружается** ради теста.

При true watchdog проверяет только local freshness 36 h и честно сообщает отсутствие off-host.
Не заменяйте им original off-host watchdog и не ставьте `offhost=1` без copy verification.
Failure виден в journal/systemd failed units; delivery во внешний alert endpoint пока не настроен.

## Включение и выключение через env

От deploy редактором измените единственную строку `.env.backup` на `BACKUP_ENABLED=true`.
Других credentials для local-only backup не требуется; off-host destination независим.
Application restart и sudo не нужны; следующий timer прочитает flag. Перед первым включением
оцените capacity/retention, затем выполните и проверьте свежий receipt/checksums:

```bash
/srv/agelto/bin/backup-local
/srv/agelto/bin/backup-local-watchdog
```

Чтобы выключить, верните `BACKUP_ENABLED=false`; следующий launcher — no-op exit 0.
Уже начатый payload этим flag не прерывается: дождитесь его завершения, не убивайте job.
Новая версия payload с SMTP archives прошла syntax/contract checks; полноценный запуск
этой версии под true намеренно не выполнялся после решения владельца отключить backup.
Предыдущие два PostgreSQL restore receipts остаются фактическими проверками прежнего payload.

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
