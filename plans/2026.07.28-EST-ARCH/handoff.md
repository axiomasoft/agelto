# HANDOFF — 2026-07-29 — after P1.6

**Next:** `run-items: task:plan-run 2026.07.28-EST-ARCH P1.7` — переименования без схемной связи
(триада `AiCallStoreRepository`, разведение `EstimateType`/`AiDocumentType`) + переезд
`HasErrorBlock`/`HasLease` в `App\Models\Concerns` (D23).

| Параметр | Значение |
|:--|:--|
| Model class | implementation (`sonnet`) |
| Effort | high |
| Capabilities | — |
| Context | continue (`/clear`) — ручной item |
| Суть | Карта `root/naming-canon.md` строка «P1.7»: переименование репозитория LLM-вызовов, разведение коллизии типов, перенос двух трейтов моделей на уровень `App\Models\Concerns`, без изменения поведения |

````
/model sonnet
/effort high
/task:plan-run 2026.07.28-EST-ARCH P1.7
````

`Exec = manual` (§3 Routing: `implementation/high`), поэтому модель и effort ставятся СЕССИЕЙ, а
`plan-run` сверяет фактический маршрут инвертированным гейтом (§9) и обязан напечатать фактическую
пару «класс/effort» в отчёте и в Update Log. Гейта владельца у P1.7 нет — три гейта владельца,
оставшиеся в плане, стоят на P2.1, P3.1 и P4.2 (`roadmap.md`).

**Правило исполнения гейтов (D89 + трактовка владельца 2026-07-29) — читать ДО первого коммита
ЛЮБОГО item'а.** Каждая строка `Validation`, являющаяся греп/`git`-командой, прогоняется на
`<base>` и печатается в Completion Notes рядом с выводом «после». Исходы: на `<base>` красный →
норма; на `<base>` уже зелёный → эскалация §10 до написания кода; зелёный недостижим в границах
`Scope` → эскалация §10.

**Правило D94:** боевые LLM-прогоны запускает ВЛАДЕЛЕЦ. Агент пишет код и тесты (LLM в тестах —
только `FakeLlmProvider`); item, чья Validation требует боевого вызова, закрывается с записью
«прогон за владельцем» и неисполненным не считается. Прямо касается P5.2 и любых
`estimate:bench:*`/`estimate:eval:*` на живом провайдере.

**Инфраструктурное ограничение ЭТОЙ раскладки репозитория (найдено на P1.5, актуально и для P1.7):**
`packages/task/lib` (`task.task_contract`/`task.route_evidence`) и
`${CLAUDE_PLUGIN_ROOT}/scripts/plan-lint.py` НЕ доступны из `agelto` (репозиторий не несёт их,
`CLAUDE_PLUGIN_ROOT` не задан в среде исполнения) — Pre-mutation/Pre-final гейт `plan-exec.md`
п.2/п.7 и `plan-lint` фиксируются как «недоступен» (fail-closed текстом, не молчаливым `allow`),
закрытие опирается на публично воспроизводимые факты самой `Validation` item'а (D77). Это
ограничение среды, не решение исполнителя — называть его в Completion Notes каждого следующего
item'а, пока раскладка не изменится.

**Новый паттерн, доказавший себя на P1.6 — «единый choke point для терминации»:** когда правка
добавляет пересекающий-многие-места инвариант (здесь — терминация `AnalysisRun`), ищи ОДНУ уже
существующую точку записи (`AnalysisStoreRepository::update()` — единственная точка персистентности
статуса анализа) и вешай инвариант туда, а не размазывай по каждому job'у/оркестратору
индивидуально. Это позволило P1.6 закрыться БЕЗ диффа в `ExtractionOrchestrator.php`/
`ScreeningJob.php`/`EstimateExtractionJob.php`, которых `Files` P1.6 не несла. Держи в уме для
будущих items с похожей формой требования («в терминальных точках контура»).

**Done:** P1.6 закрыт (🟠 Done with deviations) — item-коммит `4d08875`, `<base>=5d23626`. Единый
контур обучения/боевого разбора по `root/document-contour.md`:

- **Ось контура (D91):** `prc_documents.platform_id NOT NULL` FK + `prc_platforms.is_public`/
  nullable `base_url`; служебная площадка «Обучалка» — сидер `PlatformSeeder`
  (`Platform::TRAINING_CODE`/`Platform::training()`), в `DatabaseSeeder` ДО local/testing-гейта.
  Шесть писателей `platform_id` (пять точек создания + `EstimatePersister`), НИ одного внутри
  `->update(`.
- **Связь «документ ↔ эталон» (§2):** `prc_ai_fixtures.document_id` NULL UNIQUE `ON DELETE SET
  NULL`; `GoldImportCommand::resolveWorkingCopy` пишет связь, `EvalDiffCommand`/
  `PromptsSuggestCommand` резолвят через `$fixture->document`.
- **Прогон `AnalysisRun` (D93):** `prc_ai_analysis_runs` (partial unique один активный на версию),
  `llm_calls.analysis_run_id` без FK; `AnalysisRunStoreRepository`; `AnalysisStoreRepository`
  резолвит/терминирует прогон (`NoActiveAnalysisRun` вместо тихого null). Терминация — ЕДИНЫЙ
  choke point в `AnalysisStoreRepository::update()` (см. паттерн выше) + явная терминация в
  `ScreeningOutcomeHandler` (screening-ветвление) и в harness'ах (`FormatBenchRunner`,
  `EvalRunCommand`). Шесть launch-точек заводят прогон.
- **Правило показа (§4):** `PublicPlatformScope` — одно определение, `->publicPlatform()` на
  `Document`/`Estimate`/`Procurement`; `prc_estimates.platform_id`.
- Полные факты, все Validation с числами «до/после» на `5d23626`/`4d08875` — `phases/P1.md` P1.6
  Completion Notes.
- **🟠 (Known Deviations, четыре пункта, все docblock/DI-фиксы вне `Files`, эффект на
  `phpstan-baseline.neon` — ТОЛЬКО вычитающий, 275→255 записей):** `DatasetExample.php`
  (`@property-read documentVersion/fixture`), `DocumentVersion.php` (`@property document_id`),
  `Registry.php` (`@property platform_id`) — новые call-site'ы вскрыли пре-существующий пробел
  документирования этих трёх моделей; `LlmCacheManagerTest.php` — DI-фикс конструктора
  (`ArgumentCountError` от нового параметра `AnalysisRunStoreRepository`).

**Remaining:** 31 item ⬜ Not started, 6 🟢/🟠 (P1.1–P1.6). Фаза P1 — 6/8. Порядок: P1.7–P1.8 →
P2 (6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) → P7 (4). Батчи B1/B2 и три оставшихся гейта владельца
(P2.1, P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` (схемы, статусы, DoR) ·
`plans/2026.07.28-EST-ARCH/plan.md` §2 (правила исполнения) и §5 (**D1–D96**) ·
**`root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md` — ЗАМОРОЖЕНЫ** ·
`research/02-schema-impact-map.md` · `roadmap.md` · `phases/P1.md` (P1.6 Completion Notes) ·
`brief/{00-brief,01-refinements}.md` · `open-questions.md`.

**Open risks:**
- **Инфраструктурный разрыв task_contract/plan-lint** (см. блок выше) — сохраняется для ВСЕХ
  следующих items; называть явно в каждом Completion Notes, не молчать.
- **`config/ai.php` несёт докблочные упоминания старых имён таблиц** (комментарии) — правится при
  следующем касании файла (P1.5 Open risks, не в Scope P1.6).
- Три строки Pending Work про `CLAUDE.md` живут до P7.4 (D60/D64). Боевой прогон P5.2 — за
  владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>» — для
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.6, впредь
  требуется у P2.1, P3.1, P4.2 · `pint --dirty` автофикс — точечными Edit-правками (host-запуск
  заблокирован permission-гейтом сессии, P1.5)
- deferred: ревизия формы оставшихся гейтов — снята D89 · BigQuery-адаптер биллинга (D34) ·
  HITL-правка эталона (D48) · пере-замер F1 боевым прогоном (D52) · остаточная дупликация
  `estimate:dataset:import` (D57) · перенос дрейфа в `CLAUDE.md` — P7.4 (D60/D64) · общий инвариант
  «во всём сервисном слое нет прямых обращений к `LlmCall`» — в план НЕ вводится (D70/D79) ·
  переезд экранов транспорта из `app/Filament/Resources/Procurement/Ai/**` — вне D22 (D96) ·
  expression-индексы по `type_profile` — не заводятся до 10 000 строк реестра (§6.4) ·
  эксплуатационный отбор «какие документы пускать в дорогой разбор» — вне схемы (D92) ·
  `resources/ai/prompts/README.md` пример front-matter без `document_type` — правится при
  следующем касании промтов · `config/ai.php` докблочные упоминания старых имён таблиц — правятся
  при следующем касании файла
- open_questions: в границах плана — НЕТ. OQ-1…OQ-14 закрыты. Вне плана: P7.6 плана
  `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга) — тратит реальные деньги, несёт два
  гейта владельца
