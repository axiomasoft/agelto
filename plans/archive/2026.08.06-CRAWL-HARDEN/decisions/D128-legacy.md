---
id: D128
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P6.2 фиксирует подтверждённый award даже при анонимном победителе: `AwardData::$winner` nullable, optional `BidData::$bidNumber` отделяет идентификатор участника от порядкового номера, `SupplierResultsProcessor` пишет `prc_awards` с `winner_org_id = null`, а организацию и роль winner создаёт только при наличии идентифицируемого победителя. Анонимный повторный разбор не затирает уже обогащённый `winner_org_id`.** Отменяет обязательность имени/ИНН/ОГРН внутри самой вкладки `supplier-results`, заложенную в исходное ТЗ P6.2. **Consequences:** узкий публичный DTO-контракт расширяется двумя nullable-полями, а processor входит в `Files`; схема не меняется, потому что `winner_org_id` и `bid_number` уже существуют и nullable.

## Почему

Прямое указание владельца автономно разрешить блокировку; живая factура доказывает идентификатор участника, порядковый номер/ранг победителя и цену без реквизитов организации. Отвергнуты выдуманная организация, смешивание двух колонок, потеря award до будущего контракта и сетевой переход из HTML-parser. `RAG:— (repo-grounded: findings/P6-supplier-results-fix.md; research/15-p6.2-anonymous-winner-design.md; database/migrations/2026_07_04_100021_create_prc_bids_table.php; database/migrations/2026_07_04_100022_create_prc_awards_table.php)`
