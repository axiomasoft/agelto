# HANDOFF — 2026-07-28 — after P7.3

**Next:** manual: implementation/high

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | same-session — item |
| Суть | P7.3 закрыт 🟠 (`EvalMetrics` passport/totals, `EstimateDiffReport`/`ai:eval:diff`, спека §8.6). Следующий активный item — **P7.4** (`Exec = manual`, `implementation/high`, `plan.md` §3): очередь `prc_ai_prompt_suggestions` + LLM-критик `ai:prompts:suggest`/`ai:prompts:apply` |

```
Открой plans/2026.07.26-EST-TRAIN/phases/P7.md, раздел ### P7.4 — Очередь кандидат-патчей и
LLM-критик `ai:prompts:suggest` / `ai:prompts:apply`. Исполни item строго по его Intent/Scope
Included/Files/Implementation Rules/Validation/Deliverables. Прочитай Required Reads item'а
(plan.md §5 D8/D59/D60/D61/D64 и §2, resources/ai/prompts/README.md, PromptSeeder/
PromptsSyncCommand, PromptComposer, Correction-модель+миграция, AiRoutingSeeder,
LlmProvider-контракт) и Phase Context P7 перед началом. По завершении — Completion Notes, Pending
Work, Known Deviations, Escalation Needed, гейт закрытия (plan-lint --baseline HEAD, счёт с
атрибуцией) и запись в journal.jsonl (--command plan-run --actor implementation/high --result
<статус>), затем перезапиши handoff.md по канону `plan-protocol` §8.
```

Альтернатива (владелец разрешил OQ-3 первым): `/task:plan-run 2026.07.26-EST-TRAIN P6.2` под
`implementation/medium` — см. `waiting:`-строку в Open risks ниже.

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 1/3 (P6.1 🟢; P6.2/P6.3
ждут OQ-3, D53). **P7.1–P7.3 — 🟠 Done with deviations** (P7: 0/6 🟢, 3 items терминальны).
P7.3: `EvalMetrics::FIELD_MAP` переименован `price`→`total_price_curr` (D93) и расширен шестью
полями P4 (`unit_price_base`/`total_price_base`/`index_code`/`index_value`/
`source_position_number`/`work_type_name`); добавлены агрегаты `passport` (17 полей паспорта,
dot-путь) и `totals` (`value`/`kind`/`chapter_number`/`rate`, сопоставление по `(label, kind)`) —
`per_field`/`overall`/`row_coverage` не тронуты (D55). Новый `EstimateDiffReport` (+ DTO
`DiffReport`/`DiffEntry`): построчный/паспортный/итоговый diff gold↔предсказание, переиспользует
`EvalMetrics::flatten*()`/`fieldsMatch()` (один носитель правила сопоставления), арифметика —
`EstimateValidator` раздельно по gold и по предсказанию (D42), детерминированный байт-идентичный
JSON, усечение `rows` без потери `summary`. Команда `ai:eval:diff` — без единого LLM-вызова.
Спека §8.4 синхронизирован (`total_price_curr`), новый §8.6. Validation: EstimateDiffReport —
9 passed/37 assertions; EvalMetrics — 13 passed/33 assertions (6 старых + 7 новых); EvalRun —
6 passed/31, BenchFormats — 8 passed/31 (форма `compare()` цела); все 19 полей research/08 §4
найдены в файле; ручной прогон `ai:eval:diff` дважды → байт-идентично, `LlmCall::count()` 0/0;
phpstan OK (+1 строка baseline, известный Larastan false positive), pint PASS. Item-коммит
`09b843c`.

**Remaining:** P7.4–P7.6 (P7.4 — manual implementation/high, следующий; P7.5 — plan-exec; P7.6
гейт OQ-3 + два апрува владельца). P6.2/P6.3 — гейт OQ-3. P8 (0/5), P9 (0/6) — не начаты.

**Open risks:**
- **`waiting: OQ-3 разрешён → exec-items 2026.07.26-EST-TRAIN P6.2`** (D53, Decision pending у
  владельца) — P6.2/P6.3/P7.6 не исполняются; план законно продолжается P7.4–P7.5 на базовой
  политике представлений.
- **Долг D54 (P7.1+P7.2):** версии восьми промтов подняты при несуществующем ADR — строка «набор
  промтов изменился → перезамер победителя» обязана появиться в ADR при его создании (P6.3);
  дословные формулировки — Pending Work P7.1/P7.2.
- **Словари `#[Alias]` не доезжают до модели на боевых вызовах** (Pending P7.1 №2): `compose()`
  получает только Response-класс шага (`RowRangeChunkSource.php:145`, `WindowedChunkSource.php:224`,
  `ScreeningProcessor.php:148`) — нужен fix-item через `plan-design` (вне Scope P7.1/P7.2).
- **Словарь `ModifierKind` не сделан** (Pending P7.1 №1) — enum без `#[Alias]`, §2.3 спеки нет;
  ре-дизайн follow-up.
- **Позиционное сопоставление смет (`ordinal`) — граница `EvalMetrics`/`EstimateDiffReport`**
  (Pending P7.3): устойчивая эвристика `estimate_number → адрес → позиция` не реализована,
  всплывёт как находка P7.5/P7.6 на многосметных эталонах.
- Семантический дрейф `row_index` ↔ `source_position_number` в `EstimateHeaderMap` (v1-алиас
  «№ п/п»; состав полей — P4.5/D33). В текстах промтов «№ п/п» больше нет (тест-кейс P7.2).
- **БЛОКЕР `doc_type` (P5.4, унаследован):** 13/13 негативов корпуса без `doc_type` —
  `false_positive_rate` скрининга не измерим на реальных данных; решение владельца.
- **P3 OOM на `--prepare` (P5.5, унаследован):** `EST-XLS-KS2-001`/`EST-XLSX-LSR-002` крашат
  `SpreadsheetStrategy` полным прогоном; поэлементный `--code` — обход.
- `soffice` PATH-квирк (P5.5, унаследован) — в текущем контейнере работает (P6.1).
- Посторонний незакоммиченный дифф в дереве (не мой, не трогал): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- `phpstan-baseline.neon` растёт вне items (унаследованный кандидат в отдельный item, P5.3) — P7.3
  добавил ещё одну строку (известный Larastan false positive на relation-чейне `Analysis→
  DocumentVersion→Document`, тот же паттерн, что уже baseline'ится в `CachesCommand`/
  `AnalysisStoreRepository`/`GoldExampleRecorder`/`PipelineRetryFailed`).
- plan-lint: 0 ERROR / 10 WARN, новых от диффа item'а 0 — те же унаследованные WARN класса P1/P5/
  P6/P7 (NEEDS CLARIFICATION-маркеры, отсутствующие workflow-скрипты, длинная Update Log-строка).
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 — единственный гейт владельца P6/P7.6 (доступ к
провайдеру + апрув цены). OQ-4 (переход на 3.x-модели) — Exploration, не блокирует. `ai:eval:run`
в этом окружении не запускался (боевой провайдер вне Scope) — Validation #5 P7.3 проверен
синтетической `Analysis(result=gold)`, созданной и удалённой вручную (не осталась в БД); первый
реальный прогон `ai:eval:run` даст боевые данные для `ai:eval:diff`/P7.5/P7.6. compose-CLI сессии
падает на интерполяции `MINIO_ROOT_PASSWORD` — команды гонялись `docker exec agelto_app …` (тот же
контейнер).

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.3 Completion Notes/Pending Work; P7.4–P7.6
детализированы до DoR) · `docs/specs/v2/08-estimate-extraction.md` §8.4/§8.6 ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3/OQ-4) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
