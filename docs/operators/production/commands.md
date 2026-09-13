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

Локальный автоматический backup (root, подробности и restore receipts:
[manual backups](manual-backups.md)):

```bash
systemctl show agelto-backup-local.service -p Result -p ExecMainStatus
systemctl list-timers --all --no-pager 'agelto-backup-local*'
journalctl -u agelto-backup-local.service -u agelto-backup-local-watchdog.service --since today --no-pager
```

`BACKUP_ENABLED=false` в `.env.backup` пропускает backup/watchdog без обновления receipt;
`Result=success` в этом случае означает успешный no-op, не новую копию. Включение —
`BACKUP_ENABLED=true` по [runbook](manual-backups.md). Verified local receipt не означает
автоматический off-host backup. Для собственной отправки почты — [SMTP/DNS](server-mail.md).

Текущий source-derived inventory генерируется из root Make delegation,
`docker/Makefile` `.PHONY` targets и declared P12 script modes. Отдельный tracked файл
матрицы в корне checkout не нужен: создавайте временный артефакт из текущих исходников.
Архивный P12 snapshot остаётся provenance, не обновляется при каждом изменении runtime scripts.

Перед копированием команд сгенерируйте и проверьте текущую матрицу:

```bash
command_matrix_path=$(mktemp /tmp/agelto-command-matrix.XXXXXX.md)
python3 docker/scripts/check-command-reference.py render --output "$command_matrix_path"
python3 docker/scripts/check-command-reference.py check --output "$command_matrix_path"
less "$command_matrix_path"
```

Ожидается exit 0 у render/check; `less` закрывается клавишей q. Временный файл не содержит
секретов и не добавляется в Git. Указывайте `--output` явно: default исторический P12 snapshot
может быть stale, его нельзя перезаписывать ради успешной проверки текущего runtime.
A stale/missing/duplicate/wrong-environment or unguarded destructive row fails closed. Local fixture
output is never runtime evidence; external/mutating rows remain blank until the follow-up rehearsal.
