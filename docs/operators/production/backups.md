---
audience: operator
type: runbook
status: living
---

# Backup and recovery preparation

Новый backup проверяет восстановление PostgreSQL, выгружает текущие объекты и локальные LLM receipts,
шифрует конфигурацию и ключи через age и отправляет полный набор во внешний шифрованный репозиторий
Restic. После чтения снимка обратно он сверяет контрольные суммы и записывает `offhost=1`.
Для ранее созданных копий см. [восстановление локальных архивов OpenSSL](manual-backups.md).

`host-provision.sh apply --confirm` устанавливает скрипты и таймеры. Для работы нужны
`BACKUP_ENABLED=true` в `/srv/agelto/repository/.env.backup`, подтверждённый оператором
`/etc/agelto/operator-rehearsal-authority` под управлением root и существующий Compose wrapper.
Приложение работает независимо от этих настроек.
При переходе с ручной установки остановите прежние таймеры `agelto-backup-local.timer` и
`agelto-backup-local-watchdog.timer` после проверки их владельца и путей запуска.

Подготовьте следующие файлы с доступом для `deploy`.

- `/etc/agelto/backup-age-recipients` содержит публичные ключи age. Храните ключи восстановления отдельно от хоста.
- `/etc/agelto/backup-restic-repository` содержит адрес вида `sftp:backup-host:/srv/restic/agelto`.
- `/etc/agelto/backup-restic-password` содержит пароль Restic.

Установите права `0400` или `0600` для двух файлов Restic. Проверьте ключ SSH хоста,
задайте доступ без интерактивного запроса от `deploy` и инициализируйте отдельный репозиторий
до включения таймера. Скопируйте пароль и SSH-реквизиты в защищённое хранилище восстановления.
Сохранённый локальный статус содержит ID снимка. Для восстановления извлеките снимок в чистый каталог,
выполните `sha256sum --check --strict manifest.sha256`, затем расшифруйте нужные `.tar.age`
автономным ключом age. Restic хранит внешние снимки до отдельной операторской ротации.
[Restic SFTP](https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html#sftp)

The tracked backup package separates local contract proof from runtime evidence. Run the hermetic
carrier locally:

```bash
bash docker/tests/ingress-backup-contract.sh
```

The production sequence is fixed: take the exclusive release/backup lock, validate the closed
profile and disk floor, create a PostgreSQL custom-format dump, restore it into an isolated scratch
database, export object current-state plus configuration/secret-file metadata, hash and encrypt the
set, copy it off-host, verify the copied bytes, and only then apply retention. A local or remote copy
without a successful restore/checksum is not a verified backup.

The host installer validates the systemd service, timer, watchdog and alert units with
`systemd-analyze verify`. The operator rehearsal still needs to observe a scheduled run after reboot,
prove a stale or failed backup alert and record the resulting state in the evidence template.

Community MinIO `mc mirror` represents current-state copying. It must not be described as preserving
version history. A version-preserving design remains conditional on the pinned MinIO/`mc` capability
matrix and a destructive create/update/delete/restore fixture in the operator rehearsal.

Restore always targets a separately allowlisted `*_rehearsal` database/bucket. Production identity,
`pg_restore --clean`, volume deletion, prune-before-copy and implicit destructive flags are forbidden.
See текущий inventory команд в `commands.md` for canonical commands.
