---
id: D82
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Снести три носителя: `prc_daily_stats`, `prc_regions` и `prc_ignore_rules`** — вместе с моделями, фабриками, Filament-ресурсом `IgnoreRules`, правом `IgnoreRulePermission` и его строкой в `OperatorRole`. `prc_ignored_items` НЕ трогается — она живая. Закрывает Q14. Отвергнуты: снести только `prc_daily_stats` (остальные остались бы «задело́м», а `prc_ignore_rules` — экраном, который ничего не делает) и не сносить ничего (в компактной схеме остались бы таблицы без писателей и читателей). **Consequences:** возврат любой из трёх стоит новой миграции и восстановления файлов из git — на пустой БД дёшево, но не бесплатно; вместе с `prc_ignore_rules` исчезает операторский сценарий «завести правило игнора», которого фактически и не было (правила не применял никто); при снятии права обязателен прогон `guard:sync-roles` — он уже вызывается `DatabaseSeeder`

## Почему

Решение владельца 2026-08-06 (`brief/01-refinements.md` блок P8 п. 2) на исправленной фактуре: первый замер искал ИМЕНА таблиц в сервисах и дал ложную картину, замер по КЛАССАМ моделей показал, что мертвы только две таблицы, `prc_ignored_items` жива, а `prc_ignore_rules` полумёртвая. `RAG:— (repo-grounded: app/Services/Procurement/CsvBlockIngestor.php:183 — единственный читатель denylist; app/Guards/Admin/Roles/OperatorRole.php:44; database/seeders/DatabaseSeeder.php:26; research/09-p8-schema-design.md §1 Ф-8, §5)`
