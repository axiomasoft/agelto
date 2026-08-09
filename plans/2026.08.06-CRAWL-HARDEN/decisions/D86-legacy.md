---
id: D86
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Coupling-поверхность P8 перечислена явно** (`plan-protocol` §5): (1) `database/migrations/**` целиком — P2–P6 добавляют, P8 поглощает: забытая догоняющая миграция означает, что цель фазы не достигнута; (2) `…100069`/`…100070` — P8.2 и P8.3, файл удаляет второй; (3) `config/partitioning.php` — P4.6 (журналы) и инфраструктурные миграции P8, единый список таблиц: расхождение обрывает `run_maintenance()` целиком (прецедент `…100076`); (4) `tests/Feature/Infra/{SchemaHardeningTest,PartitionRetentionTest}.php` — единственные гейты ФОРМЫ схемы, их правка «чтобы зеленело» есть снятие гейта, а `PartitionRetentionTest` вообще включает файл миграции по пути; (5) `docs/03_Dev/database.md` — дописывают P5–P7, сводит по факту P8.4, тиражирует P9; (6) `app/Guards/Admin/{Permissions,Roles}/**` — снос права `IgnoreRulePermission` без строки роли (или наоборот) валит `guard:sync-roles`

## Почему

Видимая coupling-поверхность предупреждает рябь: автор поздней фазы видит, что его правка заденет раннюю, ДО того как напишет её. Прецедент — тот же приём в D47/D58/D72/D80
