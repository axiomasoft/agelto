# P2.2 — гидрация корпуса и парный baseline eval (M0/M0′)

Заход `plan-run` (сессия sonnet/high), 2026-08-01. Якорь дерева для всех чисел ниже — item-коммит
`19f9808` (код `EvalRunComparator`/`EvalCompareCommand`/тест; findings-коммит идёт следующим и
дерево кода между ними не меняется).

## 1. Гидрация БД из git-SSOT (D93)

Предусловие SSOT проверено ЗЕЛЁНЫМ (`estimates/manifest.json`: 24 записи, 6 `gold_path` разрешились
в существующие файлы `estimates/gold/*.gold.json`, все 6 кодов `estimates/eval-splits.json` ⊆
манифест).

```
docker exec agelto_app php artisan estimate:fixtures:import
```
→ `Готово: импортировано 24, отклонено 0.`

```
docker exec agelto_app php artisan estimate:gold:import --prepare
```
→ `Готово: документов 21 · версий 21 · записей extraction 6 · записей screening 11 · отклонено 0 · unprocessable 0.`
(P2.8 сняла блокер `EST-XLS-KS2-001` — прогон дошёл до конца без fatal error.)

```
docker exec agelto_app php artisan estimate:fixtures:verify
```
→ `ok: 24 · расхождений: 0 · лишних: 0` (все 24 строки таблицы — `ok`/`ok`/`ok`/`ok`).

```
docker exec agelto_app php artisan tinker --execute="echo DB::table('prc_ai_fixtures')->count().'/'.DB::table('prc_ai_dataset_examples')->count();"
```
→ `24/17` (17 = 6 extraction gold + 11 screening, скрининговые примеры сверх шести — как и
предсказывал Validation item'а).

`git status --porcelain estimates/` — пусто (единственный untracked файл каталога,
`estimates/gemini-analys.md`, — чужой дифф из `plan.md` §2, гидрацией не создан и не тронут).

## 2. Парный baseline (D91) — task=extraction, --split=all

Ровно ДВА платных прогона на одном дереве/корпусе (утверждено владельцем 2026-08-01, D26+D91;
Deferred/Open risks handoff — «P2.2 — ДВА полных платных прогона»). Task — `extraction` (единственный,
для которого существует `tune`/`holdout`-разбиение и который использует `EvalRunComparator`;
`screening`-примеры разбиения не имеют — `estimates/eval-splits.json` несёт только 6
extraction-кодов, — и штатный `estimate:eval:compare` не спроектирован под их формат метрик).

```
docker exec agelto_app php artisan estimate:eval:run --split=all
```

| | M0 | M0′ |
|:--|:--|:--|
| EvalRun id | **1** | **2** |
| provider/model | gemini/gemini-3.5-flash | gemini/gemini-3.5-flash |
| dataset_version | v1 | v1 |
| эталонов обработано | 4/6 | 4/6 |
| overall precision | 0.5368 | 0.5728 |
| overall recall | 0.2836 | 0.2378 |
| overall f1 | 0.2755 | 0.2674 |
| overall row_coverage | 0.6419 | 0.6452 |

**Оба прогона потеряли по 2 из 6 эталонов на лимите токенов Gemini** (см. §3) — `overall`-числа
в таблице выше посчитаны раннером только над УСПЕШНЫМИ примерами (`EvalRunCommand::renderReport`),
поэтому M0/M0′ overall НЕ являются точным входом критерия — вход критерия считается по
per-example результатам (все 6, включая f1=0 у сорвавшихся) через `estimate:eval:compare` §4.

## 3. Известный дефект контура: 2/6 эталонов рвутся по лимиту токенов Gemini (НЕ Scope P2.2)

```
[3/6] dataset_example #7 (EST-XLSX-KS2-001, holdout): Gemini hit token limit... truncated at 30641 characters
[4/6] dataset_example #10 (EST-XLSX-KS2-003, tune): Gemini hit token limit... truncated at 29546 characters
```
(M0). На M0′ — другая пара:
```
[2/6] dataset_example #4 (EST-XLS-KS2-001, tune): Gemini hit token limit... truncated at 127882 characters
[4/6] dataset_example #10 (EST-XLSX-KS2-003, tune): Gemini hit token limit... truncated at 29546 characters
```
`EST-XLSX-KS2-003` (#10) падает ДЕТЕРМИНИРОВАННО в обоих прогонах — то же самое усечение на той же
границе символов. `EST-XLS-KS2-001` (#4) и `EST-XLSX-KS2-001` (#7) падают НЕДЕТЕРМИНИРОВАННО —
успех/срыв у каждого меняется местами между M0 и M0′ при одинаковых входе, дереве и промте.

**Материальный остаток вне Scope P2.2** (Scope Excluded явно запрещает трогать маршруты/модели/
`llm_routes`/capability-матрицу): предохранителя от усечения нет, `maxTokens`/бюджет thinking для
маршрута `extraction` занижен под фактический объём вывода этих документов (запрошено раннером
≥40–90К токенов суммарно, лимит явно ниже). **Конкретное фикс-предложение (plan-protocol §8,
C3/D6):** поднять `maxOutputTokens`/thinking-бюджет соответствующего `llm_routes`-маршрута до
уровня, покрывающего наблюдённые пики (~90К для `EST-XLS-KS2-001`), ЛИБО завести ретрай с большим
бюджетом на детект усечения — отдельным follow-up item ДО того, как P2.3–P2.6 начнут сравнивать
свои прогоны с этим baseline: при пороге `PER_FIXTURE_F1_MAX_DROP=0.05` и наблюдённом разбросе
0.565 на `EST-XLS-KS2-001` (см. §4) любой следующий прогон рискует ложным `reject` от ОДНОГО
флейкового эталона, никак не связанного с реальной регрессией шага.

## 4. `estimate:eval:compare` M0 → M0′ — оба критерия

```
docker exec agelto_app php artisan estimate:eval:compare --base=1 --candidate=2 --format=md
```

Вердикт (`patch`): **reject** — `(а) tune.overall.f1 изменился на -0.1413`, `(в) tune.row_coverage
упал на 0.2500`, `(г) эталон EST-XLS-KS2-001 потерял 0.5652 f1`.

```
docker exec agelto_app php artisan estimate:eval:compare --base=1 --candidate=2 --criterion=no-regression --format=md
```

Вердикт (`no-regression`): **reject** — `(в) tune.row_coverage упал на 0.2500`, `(г) эталон
EST-XLS-KS2-001 потерял 0.5652 f1`. Оба вердикта reject исключительно из-за флейка §3, не из-за
реального изменения (между M0 и M0′ НИЧЕГО в коде/промте/корпусе не менялось).

| подвыборка | base (M0) f1 | candidate (M0′) f1 | Δf1 | base row_coverage | candidate row_coverage | Δrow_coverage |
|:--|--:|--:|--:|--:|--:|--:|
| tune (4 эталона) | 0.2528 | 0.1115 | -0.1413 | 0.5000 | 0.2500 | -0.2500 |
| holdout (2 эталона) | 0.0453 | 0.3117 | +0.2664 | 0.2839 | 0.7903 | +0.5064 |

| код эталона | подвыборка | Δf1 (M0′ − M0) |
|:--|:--|--:|
| EST-GSFX-LSR-001 | tune | 0.0000 |
| EST-XLS-KS2-001 | tune | -0.5652 |
| EST-XLSX-KS2-001 | holdout | +0.4007 |
| EST-XLSX-KS2-003 | tune | 0.0000 |
| EST-XLSX-LSR-001 | holdout | +0.1321 |
| EST-XLSX-VOR-001 | tune | 0.0000 |

## 5. Наблюдённый разброс M0↔M0′ — это и есть шум контура фазы (D91)

Разброс большой (флейк §3 — доминирующий вклад) — публикуется КАК ЕСТЬ (`Implementation Rules`,
D91: «большой разброс это ФАКТ контура... не повод перемерить до удобного числа»). Число по каждой
метрике критерия — абсолютное значение наблюдённой дельты M0↔M0′:

| метрика критерия | |Δ M0↔M0′| | штатный порог (для сравнения) |
|:--|--:|--:|
| tune.overall.f1 | **0.1413** | TUNE_F1_MIN_GAIN = 0.01 (растёт вниз/вверх — реквизит только `patch`) |
| holdout.overall.f1 | **0.2664** | HOLDOUT_F1_MAX_DROP = 0.005 |
| tune.row_coverage | **0.2500** | ROW_COVERAGE_MAX_DROP = 0.005 |
| holdout.row_coverage | **0.5064** | ROW_COVERAGE_MAX_DROP = 0.005 |
| per-fixture f1 (худший из 6, `EST-XLS-KS2-001`) | **0.5652** | PER_FIXTURE_F1_MAX_DROP = 0.05 |

Рабочее определение шума фазы (D91) для шагов D8 (P2.3–P2.6): дельта шага по метрике считается
шумом ⟺ `|Δ шага| ≤ |Δ M0↔M0′|` этой метрики из таблицы выше — а не абсолютные пороги
`EvalRunComparator` (те остаются гейтом компаратора, не определением шума). Учитывая §3, три из
пяти метрик (`holdout.f1`, `holdout.row_coverage`, `per-fixture f1`) на этом дереве целиком
объясняются флейком одного-двух эталонов, а не устройством критерия — следующий заход обязан
перепроверить это определение ПОСЛЕ фикса §3, если он будет сделан раньше P2.3.

## 6. Воспроизведение

```
docker exec agelto_app php artisan estimate:fixtures:import
docker exec agelto_app php artisan estimate:gold:import --prepare
docker exec agelto_app php artisan estimate:fixtures:verify
docker exec agelto_app php artisan estimate:eval:run --split=all   # M0 (или новый EvalRun, если счётчик уехал)
docker exec agelto_app php artisan estimate:eval:run --split=all   # M0′
docker exec agelto_app php artisan estimate:eval:compare --base=<M0 id> --candidate=<M0′ id> --format=md
docker exec agelto_app php artisan estimate:eval:compare --base=<M0 id> --candidate=<M0′ id> --criterion=no-regression --format=md
```

Числа выше НЕ воспроизводятся байт-в-байт при повторном прогоне (Gemini недетерминирован,
§3 — usечение зависит от конкретной генерации) — воспроизводимы форма измерения и порядок величин,
не сами цифры.
