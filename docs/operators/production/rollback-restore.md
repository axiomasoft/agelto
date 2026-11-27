---
audience: operator
type: runbook
status: living
---

# Rollback and restore preparation

Для local-only images используйте [manual rollback](manual-deployment.md#повторный-ручной-релиз-и-rollback):
сохранённые app/doctor IDs, previous tag, совместимая schema, scoped worker drain, смена tag
и recreate без pull/build, затем readiness/Doctor/HTTPS. Не вызывайте registry promotion helpers.
Первый release не имеет previous successful version. Dry-run или переключение между tags
одного и того же image не доказывают rollback между разными версиями/schema.

На целевом host runtime rollback между двумя **разными** app/doctor image IDs и обратный
switch проверяются отдельно; IDs, hashes и результаты сохраняются в protected журнале
конкретной операции вне Git, например `/srv/agelto/operations/<UTC-stamp>`. Schema-identical
docs release подтверждает механизм image switch, но не безопасность будущей несовместимой
миграции. После recreate используйте bounded `wait-readiness` для штатного scheduler heartbeat.
Ежедневный [manual backup](manual-backups.md) уже имеет реальный PostgreSQL restore path;
его local verification не следует путать с full off-host disaster recovery.

Локальный custom-format pg_dump сам по себе не является verified backup. `pg_restore --list`
проверяет только читаемость archive; нужны restore в отдельную DB и проверки её schema/data.
Существующий `docker/postgres/backup-and-verify.sh prod` использует legacy env names и не фиксирует
project name: не запускайте его на shared host без адаптации. Он также удаляет scratch DB,
что не подходит при запрете удаления ресурсов. Фактически выполненные scoped backup/restore
команды, archive hash и retained rehearsal DB должны быть записаны в журнале конкретной
backup/restore операции вне Git, без секретов. Исторический результат и ссылка на историю
в Git приведены в [manual deployment](manual-deployment.md#последний-зафиксированный-результат-и-история).

## Ручная локальная PostgreSQL rehearsal

От deploy, после проверки `docker inspect` labels/mounts именно `agelto_prod-postgres-1`
и `SELECT current_database(), current_user` именно `agelto|agelto`, под scoped release lock:

```bash
set -euo pipefail
umask 077
exec 9>/srv/agelto/.release.lock
flock -n 9
install -d -m 0700 /srv/agelto/backups
backup_stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_file="/srv/agelto/backups/agelto_${backup_stamp}.dump"
rehearsal_db="agelto_${backup_stamp,,}_rehearsal"
test ! -e "$backup_file"
test -z "$(docker exec agelto_prod-postgres-1 psql -U agelto -d agelto -Atc \
  "SELECT datname FROM pg_database WHERE datname = '$rehearsal_db';" </dev/null)"
docker exec agelto_prod-postgres-1 pg_dump -U agelto -d agelto -Fc </dev/null > "$backup_file"
test -s "$backup_file"
docker exec agelto_prod-postgres-1 createdb -U agelto -O agelto "$rehearsal_db" </dev/null
docker exec -i agelto_prod-postgres-1 pg_restore -U agelto -d "$rehearsal_db" \
  --no-owner --no-acl --exit-on-error < "$backup_file"
docker exec agelto_prod-postgres-1 psql -U agelto -d "$rehearsal_db" -Atc \
  "SELECT current_database(); SELECT count(*) FROM migrations; SELECT count(*) FROM users; SELECT count(*) FROM pg_tables WHERE schemaname = 'public';" </dev/null
sha256sum "$backup_file"
flock -u 9
```

Сверьте restored migration ledger/table counts с source и проверьте необходимые business data;
запишите archive hash, время, target DB и результаты. На fresh Agelto фактически были 81 migrations,
1 user, 153 public tables. Это **наблюдение конкретного релиза**, не постоянные expected counts.
Original DB не восстанавливается и volumes не удаляются. Rehearsal DB/dump сохраняются: cleanup
только отдельным согласованным действием. При неуспехе не называйте archive verified и не
перезаписывайте его повторной попыткой; создайте новый timestamp/target.

Это только локальный PostgreSQL restore. Для disaster recovery дополнительно нужны защищённые
off-host backups, secrets/config, MinIO objects и согласованная стратегия Redis; наличие локального
dump в том же host не защищает от потери сервера. Production restore требует отдельного решения.

Откат образа останавливается, если неизвестен предыдущий неизменяемый digest или совместимость
схемы явно не подтверждена. Порядок: совместимость → проверка предыдущих ID → осушение воркеров →
переключение образа в заданных рамках → ограниченная по времени проверка готовности. В ручном
режиме без CI продвижения через registry нет. Откат не удаляет volumes, не пересобирает на хосте,
не запускает `migrate:fresh` и не принимает молча несовместимую базу.

Восстановление данных требует проверенного манифеста резервной копии и изолированной
идентичности базы и bucket вида isolated `*_rehearsal`, не содержащей `prod` и `production`.
Fixture проверяет манифест, создаёт изолированную цель, восстанавливает состояние PostgreSQL,
объектов и конфигурации и проверяет готовность. Production-идентичность и разрушающие флаги
отклоняются до вызова адаптера.

Архивная fixture сама по себе не даёт receipt из runtime. Receipts реального ручного
восстановления PostgreSQL и отката образа при идентичной схеме хранятся в защищённом журнале
операций вне Git; будущее восстановление при несовместимой схеме или данных по-прежнему требует
отдельного gate владельца, реального ID резервной копии и изолированных замеров.