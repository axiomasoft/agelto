---
id: D46
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Наблюдаемость: `prc_fetches` расширяется до журнала ВСЕХ заборов (стадия, ссылка на закупку, ожидание слота, `crawler_run_id` становится nullable), а `prc_operation_logs` получает единственного писателя и read-only витрину** — четыре класса событий, значимых оператору. Отвергнуто «оставить `prc_operation_logs` без писателя и решать её судьбу в P8 вместе с `prc_daily_stats`»: таблица заведена ровно под операторскую диагностику, и без писателя требование брифа «всегда видно, где что идёт не так» закрывается только файловыми логами, недоступными из админки. **Consequences:** объём записей растёт заметно (до 4 строк на закупку плюс скачивания); ретеншн в этой фазе не вводится — партиции обслуживает `partitions:maintain`, а политика хранения остаётся долгом

## Почему

`RAG:— (repo-grounded: app/Services/Procurement/CsvBlockIngestor.php — единственный писатель prc_fetches; database/migrations/2026_07_04_100041 — crawler_run_id NOT NULL; grep "OperationLog::" app/ — ноль писателей; database/migrations/2026_07_04_100046 — схема под level/stage/source/entity)`
