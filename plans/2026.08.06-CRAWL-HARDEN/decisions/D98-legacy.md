---
id: D98
date: 2026-08-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Coupling-поверхность P2–P5 перечислена явно** (`plan-protocol` §5; у P6–P9 это уже сделано D72/D80/D86/D94): (1) `config/crawler.php` — трогают ЧЕТЫРЕ фазы (P2 — ключ фильтра, P3 — `fresh.only_fresh_margin_minutes` и очередь, P4 — калиброванные дефолты трёх уровней и снос мёртвого `proxy_max_fails`, P5 — пороги и расписание проверки): последняя по порядку фаза обязана перечитать секции предыдущих, а не переписать файл целиком; (2) `app/Models/Procurement/Crawler/Crawler.php` и `…/Crawlers/Schemas/CrawlerForm.php` — три фазы вешают структурные гейты в `saving()` (P2.2 — обязательность ОКПД2 D18, P3 — легальность `stop_policy` только у `mode = fresh` D29, P4.2 — запрет `use_proxy = true` при пустом наборе D38): гейты складываются, а не заменяют друг друга, и тест формы обязан проверять ВСЕ действующие на момент item'а; (3) `database/seeders/Procurement/CrawlerProfileSeeder.php` — три фазы правят одни и те же две записи (P2 — `okpd2Codes: ['43']`, P3.5 — сортировка и `stop_policy`, P4.2 — `use_proxy = false`): каждая следующая ОБЯЗАНА прогнать `db:seed` целиком, потому что предыдущая уже сузила легальную форму профиля; (4) `app/Dto/Procurement/Crawler/EisSearchFilter.php` + `EisCsvUrlBuilder` + три их теста — P2 и P3 добавляют поля в один DTO с явным отвержением неизвестного ключа: второй по счёту item обязан дополнить контрактный тест, а не переписать набор ожидаемых ключей; (5) `routes/console.php` — P3/P4/P5 регистрируют расписания в одном файле; (6) `app/Models/Procurement/Procurement.php` + `ProcurementStoreRepository` — P2.6 снимает мёртвые поля ОКПД2, P5.2 добавляет `detail_parse_outcome`, P6 — `succeeded_at`; (7) `resources/crawler/health/eis-baseline.json` — P5.1 заводит, P6.2 меняет статус вкладки `supplier-results` (уже зафиксировано D72). **Consequences:** порядок P2 → P3 → P4 → P5 в этих семи точках становится жёстким требованием, а не удобством: исполнение вразнобой даст молча затёртые гейты и сиды, не проходящие собственный валидатор

## Почему

Contract-first требует перечислить поверхность, трогаемую ≥ 2 фазами, в D-логе; для P2–P5 запись отсутствовала, хотя пересечение измеримо: `config/crawler.php` назван в `Files` четырёх фаз, ещё шесть файлов — в трёх и двух. `RAG:— (repo-grounded: сводка по полю `Files` всех 51 item'а, phases/P2.md–P5.md, 2026-08-07)`
