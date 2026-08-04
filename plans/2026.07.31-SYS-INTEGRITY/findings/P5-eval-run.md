# P5-eval-run — контрольный прогон и вердикт непрегрессии (P5.7)

> Deliverable P5.7 (`plans/2026.07.31-SYS-INTEGRITY/phases/P5.md`). Промты правлены ТОЛЬКО по трём
> новым полям паспорта (`regulatory_base`/`passport.regulatory_amendments`/`passport.index_letter`,
> `extraction-passport` версия 2→3), `ai:prompts:sync` применён. Сравнение — с baseline P5.4
> (`findings/P5-eval-baseline.md`, `EvalRun #22`).

## 0. Session

Session: sonnet/high (D18 `plan-run`, наследует настройки владельца).

## 1. Правка промта

`resources/estimates/ai/prompts/extraction-passport/0-extraction-passport.md` — добавлен блок
«Нормативная база сметы» (три реквизита формы 421/пр → `regulatory_base`/
`passport.regulatory_amendments`/`passport.index_letter`, явное разграничение с
`passport.reference`); `version: 2` → `3`. Остальные три файла (`extraction-rows`/
`extraction-totals`/`extraction-global`) НЕ тронуты — правило не сквозное (три новых поля целиком
принадлежат паспорту, ни один из трёх файлов их не касается).

`docker exec agelto_app php artisan ai:prompts:sync` — «Промты синхронизированы»;
`ai:prompts:sync --check` — «Дрейф файлы↔БД не обнаружен»;
`docker exec agelto_app php artisan test --filter=PromptComposition` — **12 passed** (98 assertions),
бюджет 12000 символов на шаг не превышен.

## 2. Находка ДО прогона: `EvalRun #22` (baseline P5.4) и `prc_ai_dataset_examples` пропали из dev-БД

Обнаружено первым же `estimate:eval:run --split=all` этого item'а («Датасет пуст — dry-run по
факту»): `prc_ai_dataset_examples` = 0 записей, `prc_ai_eval_runs` = 1 запись (id=1, той же
`--dry-run`-заглушкой). Между закрытием P5.6 (`57dd7a1`) и стартом этого item'а на dev-БД
произошёл `migrate:fresh` вне Scope этого item'а (тот же класс, что известный workaround
handoff'а про `prc_ai_fixtures`, но на этот раз задел ещё и `prc_ai_dataset_examples`/
`prc_ai_eval_runs` — `prc_ai_fixtures` уцелел, 24/24 по `estimate:fixtures:verify --json`).

**`EvalRun #22` (baseline) физически не существует в БД** — `estimate:eval:compare --base=22
--candidate=<...>` структурно не выполним (`EvalRun не найден`). Числа baseline при этом
ПОЛНОСТЬЮ сохранены в `findings/P5-eval-baseline.md` (Sources of truth item'а) — сравнение этого
item'а ведётся по ним, ручным повтором формулы `EvalRunComparator` (§4), как и предусматривает
Validation item'а («иначе — ручное сравнение по числам P5.4»).

**Восстановление датасета** (не входит в Scope Excluded — гидрация корпуса, не правка
контрактов/gold/метрик, тот же приём, что уже применялся в этом плане):
`docker exec agelto_app php artisan estimate:gold:import --prepare` →
«документов 24 · версий 24 · записей extraction 6 · записей screening 11». `prc_ai_dataset_examples`
= 17 (6 extraction + 11 screening) — совпадает с P2.2 (`19f9808`).

## 3. Контрольный прогон — потребовал ДВА платных захода (апрув владельца на второй)

**Заход 1** (`EvalRun #2`, окно `16:17:31`–`16:27:55`): `docker exec agelto_app php artisan
estimate:eval:run --task=extraction --split=all` → **3/6** эталонов. `dataset_example #7`
(EST-XLSX-KS2-001, holdout) и `#12` (EST-XLSX-LSR-001, holdout) — `cURL error 28: Operation timed
out after 150001/150002 ms` (провайдер не ответил вовсе, ноль байт); `#16` (EST-XLSX-VOR-001) —
известный `bcadd()`-дефект (`findings/P5-eval-baseline.md` §5, вне Scope). Holdout-подвыборка
кандидата **пуста целиком** (0/2) — ни автоматический, ни ручной вердикт непрегрессии посчитать
невозможно (`EvalRunComparator::compare()` в этой ситуации бросает `RuntimeException`: «Подвыборка
`holdout` пуста... Прогони `estimate:eval:run --split=all`»). Расход: **$0.5366** (40 вызовов:
`extract_passport`×9, `extract_rows`×31).

Владелец (диалог этого захода) одобрил второй платный прогон поверх утверждённого D45-цикла
(`baseline` + один контрольный) — заход 1 не дал пригодных для вердикта данных.

**Заход 2 — решающий** (`EvalRun #3`, окно `16:45:53`–`16:54:07`): та же команда → **5/6**
эталонов. `dataset_example #7` (EST-XLSX-KS2-001, holdout) — снова `cURL error 28` (150002 ms);
`#12` (EST-XLSX-LSR-001, holdout) — на этот раз успешно. `prompt_version_set` прогона:
`{"global":2,"extraction:rows":2,"extraction:global":2,"extraction:totals":2,"extraction:passport":3}`
— контрольный прогон бесспорно измеряет НОВУЮ версию промта. Расход: **$0.6277** (42 вызова:
`extract_passport`×10, `extract_rows`×31, `extract_totals`×1).

**Фактический расход item'а: $0.5366 + $0.6277 = $1.1643** (два захода; апрув владельца P5.4 —
«до ~$1.2» — относился к ОДНОМУ прогону baseline, второй заход одобрен отдельно этим item'ом).

## 4. Числа

**Общий (5 успешных эталонов заход 2, `EvalRun #3.metrics`):**

| Метрика | baseline (`#22`, 5 успешных) | candidate (`#3`, 5 успешных) |
|:--|:--|:--|
| precision | 0.2896 | 0.4867 |
| recall | 0.2051 | 0.2891 |
| f1 | 0.1064 | 0.2394 |
| row_coverage | 0.7081 | 0.6306 |

**Композиция «5 успешных» РАЗНАЯ между прогонами** (baseline потерял `EST-XLSX-VOR-001` на
`bcadd()`, candidate — `EST-XLSX-KS2-001` на timeout) — строка выше информационная, НЕ решающая
дельта (Implementation Rules п. 5: «сравнение прогонов разного состава эталонов компаратор
отвергает по построению»).

**По эталонам** (id — сквозной `dataset_example_id`, стабилен между прогонами; split — из
`estimates/eval-splits.json`, авторитетный источник, не колонка «split» `P5-eval-baseline.md`
§4, где `EST-XLS-KS2-001` ошибочно помечен «не в eval-splits.json»):

| id | fixture | split | baseline f1 | baseline rc | candidate f1 | candidate rc |
|:--|:--|:--|:--|:--|:--|:--|
| 1 | EST-GSFX-LSR-001 | tune | 0 | 0 | 0 | 0 |
| 4 | EST-XLS-KS2-001 | tune | 0.2589 | 1 | 0.3085 | 1 |
| 7 | EST-XLSX-KS2-001 | holdout | 0.0558 | 1 | **0 (упал, timeout)** | **0** |
| 10 | EST-XLSX-KS2-003 | tune | 0.1579 | 0.96 | 0.1634 | 0.96 |
| 12 | EST-XLSX-LSR-001 | holdout | 0.0594 | 0.5806 | 0.0897 | 0.5806 |
| 16 | EST-XLSX-VOR-001 | tune | **0 (упал, bcadd)** | **0** | 0.6353 | 0.6122 |

## 5. Вердикт непрегрессии — ручной повтор `EvalRunComparator::compare(criterion=no-regression)`

Формула воспроизведена buквально (`app/Services/Procurement/Tuning/EvalRunComparator.php`):
подвыборка агрегируется средним по ВСЕМ id подвыборки, включая упавшие (персистятся `f1=0.0,
row_coverage=0.0` — `ExtractionEvalRunner::run()` пишет `'value' => $metrics['overall']['f1'] ??
0.0`, вне зависимости от причины останова). Пороги — репо-константы (`P5-eval-baseline.md` §6),
без изменений.

| Ось | baseline | candidate | Δ | порог (max drop) | причина отказа |
|:--|:--|:--|:--|:--|:--|
| (б) holdout f1 | (0.0558+0.0594)/2=0.0576 | (0+0.0897)/2=0.0449 | **−0.0128** | 0.005 | **да** |
| (в) holdout row_coverage | (1+0.5806)/2=0.7903 | (0+0.5806)/2=0.2903 | **−0.5000** | 0.005 | **да** |
| (в) tune row_coverage | (0+1+0.96+0)/4=0.49 | (0+1+0.96+0.6122)/4=0.6431 | +0.1531 | 0.005 | нет |
| (г) per-fixture EST-XLSX-KS2-001 | 0.0558 | 0 | **−0.0558** | 0.05 | **да** |
| (г) остальные 5 эталонов | — | — | от +0.005 до +0.6353 | 0.05 | нет |

**Вердикт: `reject`** — три причины, ВСЕ сводятся к ОДНОМУ факту: `EST-XLSX-KS2-001` не ответил
за 150 с во втором заходе (`cURL error 28`, ноль байт от провайдера). Ни одна из трёх причин не
касается качества извлечения на успешно обработанных эталонах — по всем 5 успешным эталонам
candidate f1 равен или выше baseline (наибольшая просадка среди успешных — 0, наибольший рост —
+0.6353 на VOR-001, который в baseline вовсе не досчитался). Целевой holdout-эталон правки
(`EST-XLSX-LSR-001`, единственный из шести, где паспортный блок формы 421/пр печатает все три
новых поля) обработан ОБОИМИ прогонами и вырос на **+0.0303** f1 — в пределах порога, без причины
отказа.

**`EST-XLSX-KS2-001` — тот же эталон, что успешно (row_coverage=1) прошёл baseline** (44 вызова
без единого timeout) и упал в ОБОИХ заходах этого item'а (заход 1 и заход 2, дважды подряд, на
том же 150-секундном лимите `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`, config/ai.php:34) — не
единичный сбой. Правка этого item'а (`extraction-passport`) не участвует в вызовах `extract_rows`
этого документа (64 ожидаемых строки, самый тяжёлый успешный не-holdout эталон по объёму) —
причинно-следственной связи с диффом item'а нет.

**Предлагаемая правка** (вне Scope P5.7, follow-up — C3/D6): персистентно поднять
`AI_GEMINI_REQUEST_TIMEOUT_SECONDS` (канон `.env`, сейчас неявный дефолт 150 — `config/ai.php:34`)
либо специфично для тяжёлых row-range окон; временный `=300` уже использовался в P2.9 (снят,
handoff Open risks) — тот же диагноз, другой item. Кандидат — новый item, владелец не назначен.

## 6. Измеримость трёх новых полей (Deliverable item'а)

`EST-XLSX-LSR-001` (`EvalRun #3`, `analysis.result.estimates[0].passport`/`.regulatory_base`) —
все три поля непусты и БУКВАЛЬНО совпадают с паспортным блоком документа (`estimates/files/ЛСР
02-01-01 (Ремонт кровли).xlsx`, строки G11/G12/G13, дамп §14 `research/06-estimate-completeness.md`
воспроизведён для этого файла отдельно):

| Поле | Значение LLM | Совпадает с документом |
|:--|:--|:--|
| `regulatory_base` | «Приказ Минстроя России от 30.12.2021 № 1046/пр; …» (9 приказов через «;») | да, дословно (G11) |
| `passport.regulatory_amendments` | «Приказ Минстроя России от 18 мая 2022 г. № 378/пр, …» | да, дословно (G12) |
| `passport.index_letter` | «Письмо Минстроя России от 23.08.2024 № 48886-ИФ/09» | да, дословно (G13) |
| `passport.reference` | `null` | верно — документ не печатает «Основание» отдельной строкой (не спутано с нормативной базой) |

Правило промта сработало на единственном эталоне, где D46 допускает измеримость (вердикт
`research/06-estimate-completeness.md` §10 остаётся в силе: F1 по этим трём полям НЕ меряется —
`GsfxGoldMapper`/`SheetGoldMapper` их не заполняют ни на одном из шести эталонов, покрытие меряется
только боевыми прогонами, не eval'ом). Validation item'а «новые поля непусты хотя бы на одном
эталоне» — выполнено.

## 7. Итог

- **Вердикт непрегрессии (§5): `reject`**, единственная причина — инфраструктурный timeout
  `EST-XLSX-KS2-001` в ОБОИХ заходах, не связанный с диффом item'а (§5). Правка НЕ откатывается —
  тот же приём, что P2.5 (`M2`/`M3c`, `research/03-prompt-contract.md` §8.12): единственная причина
  отказа изолирована и не касается диффа.
- **Измеримость (§6): достигнута** — три новых поля паспорта заполняются моделью корректно и
  дословно на эталоне, чей документ их печатает.
- **Открытая находка вне Scope**: пропажа `EvalRun #22`/`prc_ai_dataset_examples` из dev-БД (§2) —
  Open risks handoff'а, не блокер закрытия (восстановлено штатной командой).
- **Открытая находка вне Scope**: систематический (дважды подряд) timeout `EST-XLSX-KS2-001` на
  150-секундном лимите (§5) — кандидат отдельного fix-item, владелец не назначен.
