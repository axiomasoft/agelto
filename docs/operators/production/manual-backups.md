---
audience: operator
type: runbook
status: living
---

# Восстановление старых локальных бэкапов OpenSSL

Для настройки автоматических бэкапов используйте [инструкцию age и Restic](backups.md).
Эта процедура рассчитана на уже сохранённые локальные бэкапы с архивами `.tar.enc`.
Для расшифровки нужен исходный `/srv/agelto/backup-encryption.key` или его защищённая копия.

Выберите существующий каталог по записи `backup_dir` из `latest.status` или из журнала
операции. От пользователя с доступом к копии и ключу проверьте контрольные суммы
и извлеките конфигурацию в отдельный приватный каталог.

```bash
set -euo pipefail
umask 077
backup_dir=/srv/agelto/backups/local/REPLACE_WITH_VERIFIED_STAMP
key_file=/srv/agelto/backup-encryption.key
cd "$backup_dir"
sha256sum --check --strict manifest.sha256

restore_dir=$(mktemp -d)
for name in config-secrets smtp-config smtp-dkim; do
    if [[ $name != config-secrets && ! -f "$name.tar.enc" ]]; then
        continue
    fi
    mkdir "$restore_dir/$name"
    openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$key_file" -in "$name.tar.enc" |
        tar -xzf - -C "$restore_dir/$name" --no-same-owner --no-same-permissions
done
printf 'Извлечённые архивы: %s\n' "$restore_dir"
```

Старые архивы использовали AES-256-CBC с PBKDF2. Контрольные суммы обнаруживают
случайное повреждение файлов. Используйте копии из доверенного хранилища. Не
выводите содержимое ключа и извлечённых секретов. Манифесты в `objects/` описывают
текущее состояние на момент выгрузки. Архивы Redis и история версий объектов
в эти копии не входят.

Для PostgreSQL используйте шаги создания отдельной БД, `pg_restore` и проверки данных из
[процедуры rehearsal](rollback-restore.md#ручная-локальная-postgresql-rehearsal).
В качестве `backup_file` укажите уже проверенный `"$backup_dir/postgres.dump"`. Новый
`pg_dump` для восстановления сохранённой копии не требуется.
