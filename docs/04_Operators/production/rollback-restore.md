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
switch проверяются отдельно и записываются в root deployment report. Schema-identical
docs release подтверждает механизм image switch, но не безопасность будущей несовместимой
миграции. После recreate используйте bounded `wait-readiness` для штатного scheduler heartbeat.
Ежедневный [manual backup](manual-backups.md) уже имеет реальный PostgreSQL restore path;
его local verification не следует путать с full off-host disaster recovery.

Локальный custom-format pg_dump сам по себе не является verified backup. `pg_restore --list`
проверяет только читаемость archive; нужны restore в отдельную DB и проверки её schema/data.
Существующий `docker/postgres/backup-and-verify.sh prod` использует legacy env names и не фиксирует
project name: не запускайте его на shared host без адаптации. Он также удаляет scratch DB,
что не подходит при запрете удаления ресурсов. Фактически выполненные scoped backup/restore
команды и retained rehearsal DB должны быть записаны в root deployment report.

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

Image rollback stops unless the previous immutable digest is known and schema compatibility is
explicitly green. Its order is compatibility → verify previous IDs → worker drain → scoped image
switch → bounded readiness. In manual/no-CI mode there is no registry promotion. It
does not delete volumes, rebuild on the host, run `migrate:fresh` or silently accept an incompatible
database.

Data restore requires a verified backup manifest and an isolated `*_rehearsal` database/bucket
identity that contains neither `prod` nor `production`. The fixture verifies the manifest, creates
the isolated target, restores PostgreSQL/object/config state and checks readiness. Production
identity and destructive flags fail before the adapter is called.

The archived P12 fixture itself supplies no runtime receipt. Actual manual PostgreSQL restore and
schema-identical image rollback receipts belong to the root deployment report; future incompatible
schema/data restore still requires its own owner gate, real backup ID and isolated measurements.
