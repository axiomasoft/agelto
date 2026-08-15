---
id: D40
date: 2026-08-09
status: accepted
item: P11.1
items: [P11.1, P11.2, P11.3, P11.5, P11.6, P12.7]
supersedes: []
superseded_by: null
---
## Решение

P11 использует TIA только для локального edit-loop: `pest()->tia()->locally()`. Обычная
item/phase/CI-приёмка выполняет полный suite с `--ci --no-tia`; отдельный trusted main/schedule
workflow записывает `pest-tia-baseline`, после чего конфиг получает `baselined()`. Parallel и
time-balanced sharding не включаются «по числу CPU»: P11.1 сравнивает serial/2/4 processes и
1/2/4 shards, а P11.2 принимает самый маленький режим, который трижды проходит без расхождения
test-name/status set, даёт ≥20% median wall-time improvement и не расходует >125% runner-minutes.
Иначе канон остаётся serial с честным benchmark verdict. RAG:✅ 2026-08-09 official Pest TIA,
optimizing and CI docs; selection thresholds are internal policy from
`research/13-P11-execution-design.md`.

## Почему

Текущий `always()->locally()` расходится с актуальным официальным контрактом, а replay не является
clean-checkout evidence. Одновременно полный запрет parallel/sharding оставил бы проверенную
возможность неиспользованной. Измеряемый branch удерживает скорость подчинённой изоляции,
детерминизму и реальной стоимости CI.

## Consequences

Историческое решение `plans/archive/2026.08.01-L13-PEST5.md` D6 больше не применимо только в части
«shared baseline не заводится»: тогда CI отсутствовал и был отложен именно в P11. D8 о no-TIA
приёмке сохраняется. `tests/.pest/shards.json` и matrix jobs появляются лишь при прошедшем пороге;
отсутствие ускорения не маскируется как отклонение или незавершённая оптимизация.
