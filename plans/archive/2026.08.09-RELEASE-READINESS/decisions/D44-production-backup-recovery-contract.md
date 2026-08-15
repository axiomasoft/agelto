---
id: D44
date: 2026-08-09
status: accepted
item: P12.3
items: [P12.3, P12.5, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Production backup set включает PostgreSQL custom-format dump с успешным isolated `pg_restore`,
object/media data с сохранением нужной version semantics, зашифрованную recovery-копию file secrets
и configuration/private-package/image lock manifests. Redis/cache, logs, private source checkout и
rebuildable images не считаются authoritative restore inputs. RPO, RTO, retention, encryption-key
custodian и off-host destination задаются owner-approved production profile; пустое поле блокирует
go-live. RAG:✅ 2026-08-09 official PostgreSQL/Docker/MinIO evidence in
`findings/P12-ubuntu-deployment-rag-2026-08-09.md`; policy ownership is internal.

## Почему

DB-only или local-only dump не восстанавливает object/media и секретный runtime contract, а наличие
файла не доказывает restore. Универсальные RPO/RTO нельзя честно придумать без business owner;
машинно обязательный profile сохраняет owner gate без fail-open defaults.

## Consequences

P12.3 не закрывается до утверждения профиля и первого verified off-host backup. P12.5 обязан
восстановить полный set в изолированной среде и проверить приложение; `mc mirror` без version history
не принимается там, где policy требует versions.
