# HANDOFF — 2026-08-01 — after P2.2

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P2.3

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P2.3: атрибут `#[Description]` + reflection-ридер `ContractAttributeReader` (D92), перенос описаний из докблоков на контрактах пяти шагов, арх-гейт покрытия; замер M1 против baseline P2.2 |

```
/task:plan-run 2026.07.31-SYS-INTEGRITY P2.3

РЕЖИМ: exec — НОВЫЙ item P2.3 (первый после закрытия предусловия P2.2).
ВХОД: `plans/2026.07.31-SYS-INTEGRITY/phases/P2.md` item P2.3 (все 16 полей) +
  `plans/2026.07.31-SYS-INTEGRITY/research/03-prompt-contract.md` §5.1–§5.3 (сигнатура атрибута,
  контракт ридера, таблица «поле → куда переезжает») + Deliverables P2.2
  (`plans/2026.07.31-SYS-INTEGRITY/findings/P2-eval-baseline.md` — id M0/M0′, разброс-шум).
СОСТОЯНИЕ ДЕРЕВА: P2.2 закрыт двумя item-коммитами (19f9808 код+тесты, 684ddfb findings) +
  bookkeeping-коммитом этого захода — рабочее дерево чистое от диффа P2.2. Чужой дифф
  (`.env.example`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`,
  `.swissknife/`, `plans/2026.07.31-SYS-INTEGRITY/.claude/`, `plans/2026.07.31-SYS-INTEGRITY/.swissknife/`)
  остаётся нетронутым (`plan.md` §2).
СКОУП: атрибут `App\Attributes\Ai\Description` + `ContractAttributeReader` (три метода:
  `descriptions()`/`aliases()`/`caseAliases()`, D92) + перенос (НЕ дублирование — bullet удаляется
  из докблока) описаний на контрактах пяти шагов + расширение `#[Alias]` + арх-гейт покрытия
  (`ContractDescriptionCoverageTest`, список исключений с причинами). Замер: `estimate:eval:run
  --split=all` (M1) + `estimate:eval:compare --base=<M0-id> --candidate=<M1-id>
  --criterion=no-regression` → `verdict: accept`, дельта сопоставлена с шумом M0↔M0′ из
  `findings/P2-eval-baseline.md` §5 (tune.f1=0.1413, holdout.f1=0.2664, tune.row_coverage=0.2500,
  holdout.row_coverage=0.5064, per-fixture f1=0.5652 — но см. НЕ ТРОГАТЬ ниже про флейк).
НЕ ТРОГАТЬ: `JsonSchemaGenerator`/`GeminiResponseSchemaAdapter`/`PromptContextGenerator` (следующие
  items) · состав/типы/обязательность полей контрактов (P5) · докблок-парсинг как фолбэк ·
  M0 (EvalRun #1) baseline-эталон — если M1 упадёт по `EST-XLS-KS2-001`/`EST-XLSX-KS2-003`
  (флейк токен-лимита Gemini, `findings/P2-eval-baseline.md` §3, вне Scope P2.2/P2.3, маршруты/
  модели — Excluded) — это ИЗВЕСТНЫЙ шум контура, не регрессия шага; не чинить `llm_routes` внутри
  P2.3, эскалировать при сомнении.
```

**Done:** **P2.1 закрыт 🟢** (item-коммит `ced2a4f`). **P2.8 закрыт 🟠** (item-коммиты `b51ce3a`+
`b05235f`) — разреженный обход `TextGridRenderer` снял OOM на `EST-XLS-KS2-001`, лимиты подготовки
стали настройками (`ai.prepare.xlsx_max_cells`/`ai.prepare.gold_import_memory_limit`).

**Этим заходом (`plan-run`, sonnet/high) закрыт P2.2 🟢 Done** — item-коммиты `19f9808`
(режим `no-regression` + `--criterion=` в `EvalRunComparator`/`EvalCompareCommand`, 4 теста) и
`684ddfb` (`findings/P2-eval-baseline.md`). Гидрация корпуса из git-SSOT (D93): `fixtures:import`
24/24 (0 отклонено), `gold:import --prepare` 21 документ/21 версия/6 extraction/11 screening
(0 отклонено/0 unprocessable — блокер `EST-XLS-KS2-001` снят P2.8), `fixtures:verify` 24 ok/0/0,
`prc_ai_fixtures`=24/`prc_ai_dataset_examples`=17. Парный baseline (D91): **EvalRun #1 (M0)**
4/6 succeeded, f1=0.2755, row_coverage=0.6419; **EvalRun #2 (M0′)** 4/6 succeeded, f1=0.2674,
row_coverage=0.6452. `estimate:eval:compare --base=1 --candidate=2` — `reject` в обоих режимах
(`patch`, `no-regression`), целиком объясняется флейком §3, не реальным изменением. Разброс
M0↔M0′ зафиксирован как определение шума фазы: tune.f1=0.1413, holdout.f1=0.2664,
tune.row_coverage=0.2500, holdout.row_coverage=0.5064, худший per-fixture f1
(`EST-XLS-KS2-001`)=0.5652.
Тесты: `EvalCompare --no-tia` 8 passed/21 assertions; `EvalRun --no-tia` 11 passed/46 assertions
(остался зелёным). Pint/PHPStan по `Files` item'а — чисто; полный `phpstan analyse` на всём
дереве — `[OK] No errors`, `phpstan-baseline.neon` не вырос (устранена собственная находка —
докблок `@param` с литерального юниона на `string`, инвариант держит runtime-guard).

**Material-находка вне Scope P2.2 (флейк токен-лимита Gemini):** 2 из 6 gold-эталонов рвутся по
лимиту токенов Gemini в КАЖДОМ из M0/M0′ (`EST-XLSX-KS2-003` — детерминированно в обоих;
`EST-XLS-KS2-001`/`EST-XLSX-KS2-001` — недетерминированно, меняются местами). Доминирует в
наблюдённом разбросе (per-fixture f1 = 0.5652 против порога `PER_FIXTURE_F1_MAX_DROP`=0.05 — в
11× больше). Фикс-предложение: поднять `maxOutputTokens`/thinking-бюджет маршрута `extraction`
(наблюдённый пик ~90К токенов на `EST-XLS-KS2-001`) либо завести ретрай с увеличенным бюджетом —
отдельным follow-up item'ом ДО того, как P2.3–P2.6 начнут сравнивать свои прогоны с этим baseline,
иначе любой шаг рискует ложным `reject` от этого ОДНОГО флейкового эталона. Полный разбор —
`findings/P2-eval-baseline.md` §3, §5.

**Lint:** `python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py
plans/2026.07.31-SYS-INTEGRITY --baseline HEAD` — см. итоговое число ниже этого захода (гейт
закрытия item'а прогнан на дереве ПОСЛЕ перезаписи этого файла, шаг 4 `plan-exec.md`).

**Remaining:** 40 item из 53 (было 41 — P2.2 закрыт). Внутри фазы очередь: P2.1 → P2.8 → P2.2 →
**P2.3** (следующий) → P2.4 → P2.5 → P2.6 → P2.7. Дальше по очереди D63: `P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.15.0; §2 Execution Rules; §3 Routing поitem'ный, строка
P2.3: implementation/high/manual/full; §5 D1–D97; §4 Status Board — P2 2/8) · `phases/P2.md`
(ТЗ items; P2.2 Completion Notes — числа гидрации+baseline; P2.3 — следующий) ·
`findings/P2-eval-baseline.md` (гидрация + M0/M0′ + шум D91 + флейк §3) ·
`findings/P2-textgrid-oom-2026-08-01.md` (диагноз+фикс P2.8) ·
`findings/P2-corpus-state-2026-08-01.md` · `research/03-prompt-contract.md` ·
`findings/P2-vendor-structured-output.md` · `roadmap.md` · `index.md` · `open-questions.md` ·
`journal.jsonl` · `brief/01-refinements.md`.

**Open risks:**

- **Флейк токен-лимита Gemini на 2/6 extraction gold-эталонов** (НОВОЕ, P2.2 Pending Work) —
  доминирует в измеренном шуме фазы, риск ложного `reject` на P2.3–P2.6 без фикса маршрута
  (Scope Excluded этой фазы) — см. выше.
- **`estimate:gold:import --prepare` не переводит документ в `prepared`** (P2.8 Known Deviations) —
  не блокирует гидрацию/eval (проверено P2.2), но стоит держать в уме в Validation следующих items.
- **Дешёвый size-gate слеп на легаси `.xls`** — класс риска остаётся (P2.8 Scope Excluded).
- **DB частично гидрирована, НЕ откатана**: `prc_ai_fixtures`=24, `prc_ai_dataset_examples`=17,
  `prc_ai_eval_runs`≥2 (M0, M0′), документ id 8 (`EST-XLS-KS2-001`) несёт `text_grid`-представление.
- **`EST-PDF-SCAN-001` (id 7)** в шестёрку обязательных gold НЕ входит, baseline не блокирует.
- **Представления пересоздаются на ТЕКУЩЕМ образе** — сопоставимо внутри P2, не с числами донора.
- **Деньги.** P2.2 израсходовал ОБА утверждённых платных прогона (D26+D91). Дальше — по одному на
  шаг D8 (P2.3–P2.6), плюс три на варианты OQ-2 в P2.5.
- **Расхождение порядка свойств между двумя носителями живёт в боевом коде СЕЙЧАС** — чинится P2.4.
- **Пол префикса может не сработать как защита** — байтовый якорь не переведён в токены,
  `[UNVERIFIED]` для `gemini-3.1-flash-lite`. Обязанность P2.5 (D90).
- **Канал проекта помечен вендором Legacy** — вход EOL-мониторинга, P2.7 фиксирует строкой риска.
- **Строка Update Log для `plan-audit P1` (заход 2) отсутствует** — вне скоупа.
- **A1 аудита P1 (Major) не закрыт**: потребление `ProxyRequirements->country` не покрыто
  дискриминирующим тестом — решение владельца.
- **Partition-drift — 11 падений полного сюита вне предмета плана**; фикс-предложение —
  `phases/P1.md` P1.10 Pending Work. Два красных arch-теста `CoreKnowsNoDocumentTypeTest`
  исключены поимённо (D86).
- **`verify-citations.py` — находки вне диффа**; инструмента в `plans/` этого репозитория нет (D76).
- **`PromptCanonReader.php`** несёт пред-существующую ссылку на `Services\Procurement` внутри
  `Llm` — PROMOTE-CANDIDATE, не предмет фазы.
- **`docker compose` на хосте**: `MINIO_ROOT_PASSWORD`-интерполяция падает — обходка
  `docker exec agelto_app` рабочая, использовалась и этим заходом.
- **Незапланированные файлы в рабочем дереве**: `M .env.example`, untracked `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife/`,
  `plans/2026.07.31-SYS-INTEGRITY/.claude/`, `plans/2026.07.31-SYS-INTEGRITY/.swissknife/` — чужой
  дифф из `plan.md` §2, не трогать.
- **Кросс-планная зависимость** P5 от `2026.07.26-EST-TRAIN` — сверяется перед стартом P5.
  **P4.7 — точка невозврата по схеме**: P5 и P6 обязаны быть терминальны до него.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* `docker exec agelto_app` вместо `docker compose exec app` в неинтерактивных
  оболочках агента.
- *Deferred:* строка Update Log для `plan-audit P1` захода 2 · A1/A2/A3/A6 аудита P1 · правка ТЗ
  P1.2/P1.3 (D20) · перенос слоёв класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) ·
  положения (3)–(5) целевой модели хранения (D72) · переход транспорта ЕИС на XML/SOAP (D14) ·
  переименование дисков (D53) · судьба таблицы кэша (D75) · связь «выделенные узлы под конкретный
  профиль» (D80) · `ProxyForm::COUNTRIES` (D86) · починка двух pre-existing
  `CoreKnowsNoDocumentTypeTest` (D86) · разовый флейк `CsvBlockIngestorTest` · partition-drift
  11 падений (P1.10 Pending Work) · миграция на Interactions API вендора (мониторинг) · починка
  слепого size-gate на легаси `.xls` (P2.8 Scope Excluded) · статус `EST-PDF-SCAN-001` (вне
  шестёрки gold) · решение о диспетче `PrepareDocumentJob` из `estimate:gold:import --prepare`
  (P2.8 Pending Work) · **фикс лимита токенов Gemini на маршруте `extraction`** (НОВОЕ, P2.2
  Pending Work — до P2.3–P2.6, иначе риск ложного `reject`).
- *Open questions:* OQ-1 **закрыт** (D71) · **OQ-2** (судьба `PromptContextGenerator`) — снимает
  P2.5 · OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал) · OQ-5 (продуктовый
  контур, сверяется в P7.8).
