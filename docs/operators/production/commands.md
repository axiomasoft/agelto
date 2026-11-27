---
audience: operator
type: reference
status: living
---

# Production commands

Для текущего ручного режима без CI: [полный manual runbook](manual-deployment.md).
На настроенном host используйте exact wrapper (от deploy):

```bash
cd /srv/agelto/repository
/srv/agelto/bin/compose ps
/srv/agelto/bin/compose logs --tail=100 app horizon scheduler
/srv/agelto/bin/compose logs --follow --tail=100 app horizon scheduler
make prod:readiness COMPOSE_PROD=/srv/agelto/bin/compose
make prod:doctor COMPOSE_PROD=/srv/agelto/bin/compose
```

`logs --follow` завершается Ctrl-C без остановки services. Wrapper фиксирует project,
production env и оба Compose files. Не выполняйте `prod:config` с выводом всего environment
после добавления external credentials. Registry-oriented `prod:deploy:*` здесь не используются.

Установленный `host-provision.sh` сервис backup работает от `deploy`.
Проверка состояния таймеров и последнего прогона:

```bash
systemctl show agelto-backup.service -p Result -p ExecMainStatus
systemctl list-timers --all --no-pager 'agelto-backup*'
journalctl -u agelto-backup.service -u agelto-backup-watchdog.service --since today --no-pager
```

При `BACKUP_ENABLED=false` сервис пропускает работу. Завершение подтверждает
`offhost=1` и ID снимка Restic в `/srv/agelto/backups/latest.status`.
Условия включения, age-шифрование и проверка восстановления описаны в [runbook backup](backups.md).
Старые локальные копии разбирает [исторический runbook](manual-backups.md).

## Current command inventory

Текущий source-derived inventory генерируется из root Make delegation,
`docker/Makefile` `.PHONY` targets и declared production script modes. Отдельный tracked файл
матрицы в корне checkout не нужен: создавайте временный артефакт из текущих исходников.

Перед копированием команд сгенерируйте и проверьте текущую матрицу:

```bash
command_matrix_path=$(mktemp /tmp/agelto-command-matrix.XXXXXX.md)
python3 docker/scripts/check-command-reference.py render --output "$command_matrix_path"
python3 docker/scripts/check-command-reference.py check --output "$command_matrix_path"
less "$command_matrix_path"
```

Ожидается exit 0 у render/check; `less` закрывается клавишей q. Временный файл не содержит
секретов и не добавляется в Git. Для render/check обязателен явный `--output`: проверяется текущий артефакт из исходников.
A stale/missing/duplicate/wrong-environment or unguarded destructive row fails closed. Local fixture
output is never runtime evidence; external/mutating rows remain blank until the follow-up rehearsal.
