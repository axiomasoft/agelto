# HANDOFF — 2026-07-28 — after P7.4

**Next:** `/task:plan-exec 2026.07.26-EST-TRAIN P7.5`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — Routing §3: `implementation/medium`, `Exec=plan-exec` |
| Context | continue (/clear) — ручной item |
| Суть | P7.4 закрыт 🟠 (очередь `prc_ai_prompt_suggestions`, `PromptCritic`/`PromptPatchApplier`, `ai:prompts:suggest`/`apply`, девятый промт-шаг критика, спека §6.1). Следующий активный item — **P7.5** (`Exec=plan-exec`, `implementation/medium`, `plan.md` §3): `prc_ai_eval_runs.prompt_version_set`, `--split` у `ai:eval:run`, `ai:eval:compare`, `estimates/eval-splits.json`, `research/14-prompt-tuning-protocol.md` |

```
/task:plan-exec 2026.07.26-EST-TRAIN P7.5
```

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 1/3 (P6.1 🟢; P6.2/P6.3
ждут OQ-3, D53). **P7.1–P7.4 — 🟠 Done with deviations** (P7: 0/6 🟢, 4 items терминальны).
P7.4: таблица `prc_ai_prompt_suggestions` (uuid7, `kind`/`status` enum, FK
`analysis_id`/`fixture_id`/`eval_run_id` nullable, `llm_call_id` — plain bigint без constraint,
партиционированная цель) + модель/фабрика/enum'ы `PromptSuggestionKind`/`PromptSuggestionStatus` +
репозитории `PromptSuggestionStore/ReadRepository`. `PromptCritic` — diff-отчёт (P7.3) + текущая
композиция промта шага USER-частью, системная часть — ТОЛЬКО промт критика (D64, без tools);
дедуп после структурного ответа по `(kind, prompt_key, нормализованный текст)`. `PromptPatchApplier`
— гейт `status=approved` внутри сервиса; `prompt_patch` бампает `version` во front-matter файла
(читает ТЕКУЩУЮ версию из самого файла, не из `base_version` записи) и сохраняет остальные поля;
`correction_hint` создаёт `prc_ai_corrections(is_reusable=true)`. Девятый промт-шаг
`PromptStep::PromptCritic` (`resources/ai/prompts/prompt-critic/0-prompt-critic.md`) + строка
маршрута `AiRoutingSeeder` (`any/prompt_critic/priority=0/gemini-2.5-flash-lite`, без эскалации).
Команды `ai:prompts:suggest`/`ai:prompts:apply` (оба с `--dry-run`). Спека §6.1. Validation: migrate
зелёный (24 колонки), сиды зелёные (`tinker`: маршрут + промт критика засеяны), `--filter=
PromptSuggest` 4 passed/20 assertions, `--filter=PromptApply` 4 passed/27 assertions,
`--filter=PromptSeeder`/`AiRoutingSeeder`/`PromptComposition` зелёные (промтов стало 9), регрессия
`--filter=Ai` 223 passed/869 assertions, phpstan 0 ошибок (+1 строка baseline, известный паттерн),
pint PASS. Item-коммит `ebd0a1f`.

**Remaining:** P7.5 (plan-exec, следующий) — P7.6 гейт OQ-3 + два апрува владельца. P6.2/P6.3 — гейт
OQ-3. P8 (0/5), P9 (0/6) — не начаты.

**Open risks:**
- **`waiting: OQ-3 разрешён → exec-items 2026.07.26-EST-TRAIN P6.2`** (D53, Decision pending у
  владельца) — P6.2/P6.3/P7.6 не исполняются; план законно продолжается P7.5 на базовой политике
  представлений.
- **dev-контейнер: bind-mount не даёт `www-data` право записи в `resources/ai/prompts/**`**
  (владелец файлов — хостовый uid 1000; прод-образ делает `chown -R www-data:www-data
  /var/www/html` при сборке — там `ai:prompts:apply` пишет штатно). Для тестов этой сессии
  `www-data` добавлен в группу `hostgrp` (gid 1000) ВНУТРИ уже запущенного контейнера `agelto_app`
  (`groupadd`/`usermod`, эфемерно) — не переживёт `docker compose up --build`/рестарт с пересборкой;
  при следующей пересборке шаг придётся повторить либо закрыть отдельным dev-tooling item'ом.
- **Долг: `PromptsSuggestCommand` дублирует резолв `Analysis`/gold-файла из `EvalDiffCommand`**
  (~40 строк) — Files P7.4 не включали общий сервис-резолвер; кандидат в отдельный item/`plan-design`
  (выделить `App\Services\Procurement\Extraction\*`-резолвер, которым пользуются оба).
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
- `phpstan-baseline.neon` растёт вне items (унаследованный кандидат в отдельный item, P5.3) — P7.4
  добавил ещё одну строку (тот же паттерн relation-chain false positive, что уже baseline'ится в
  `EvalDiffCommand`/`CachesCommand`/`AnalysisStoreRepository`/`GoldExampleRecorder`/
  `PipelineRetryFailed`).
- plan-lint: 0 ERROR / 10 WARN, новых от диффа item'а 0 — те же унаследованные WARN класса P1/P5/
  P6/P7 (`NEEDS CLARIFICATION`-маркеры, отсутствующие workflow-скрипты, длинная Update Log-строка).
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 — единственный гейт владельца P6/P7.6 (доступ к
провайдеру + апрув цены). OQ-4 (переход на 3.x-модели) — Exploration, не блокирует. `ai:eval:run`
в этом окружении не запускался (боевой провайдер вне Scope). compose-CLI сессии падает на
интерполяции `MINIO_ROOT_PASSWORD` — команды гонялись `docker exec agelto_app …` (тот же
контейнер).

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.4 Completion Notes/Pending Work; P7.5–P7.6
детализированы до DoR) · `docs/specs/v2/08-estimate-extraction.md` §6.1/§8.4/§8.6 ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3/OQ-4) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
