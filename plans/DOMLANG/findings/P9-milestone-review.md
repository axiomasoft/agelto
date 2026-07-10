# P9.1 Deliverable — Cross-phase milestone review (final)

**Дата:** 2026-07-11
**Харнесс:** `.claude/workflows/estai-milestone-review.js` (`scriptPath`, args-объект, D36)
**Покрытие:** P1–P8 (все закрытые фазы) · `gate: true` · ревьюеры: `agelto-reviewer`,
`agelto-security-review`, `agelto-db-impact` (opus/xhigh fan-out → opus/high adversarial verify →
opus/xhigh synthesis).
**Прогон:** 19 агентов, 0 ошибок; fan-out — 15 находок от 3/3 ревьюеров; adversarial verify —
14/15 пережили опровержение; synthesis — `verdict: PASS`, `blocking_findings: []`.

---

## ВЕРДИКТ: ATTENTION

Маппинг P9.1: `gate.verdict = PASS` при непустых surviving (14, максимум MED) → **ATTENTION**.
Ноль HIGH/CRITICAL → по D37 **reopen-тикетов нет**. Все пережившие находки — либо подтверждения
связности («чисто»), либо pre-existing residue **вне scope всех фаз DOMLANG** (EST-AI-эры,
не тронуто P1–P8), либо расхождение формулировки брифа с кодом. Ни одна не является регрессией
DOMLANG и ни одна не оспаривает основание ADR-0006/0007/0008.

**Синтез-резюме харнесса (дословная суть):** все 7 focus-утверждений подтверждены против
закоммиченного кода — глоссарий v2 анкорен на реальный ренейм P3
(`registry_number`/`registry_guid`/`source_stage`/`etp_number`, миграция 100011); мёртвые префиксы
не воскрешены и новых в схеме/код-путях нет; ADR-0006 namespace-ренейм НЕ начат; ADR-0007 OCDS —
exchange-layer, `ocds-mapping.md` согласован с миграциями; ADR-0008 provenance thin slice
(спекулятивные колонки = 0 вне `plans/`); `StatusMappingSeeder` идемпотентно сидирует 4 реальные
`fz44`-пары до local/testing-гейта (`canonical_status` не уезжает в `unknown`); double-dispatch guard
корректен по ADR-0009/D25. Security-оси чисты (нет authz/IDOR/инъекций/секретов/mass-assignment в
тронутом P2–P5 коде).

---

## Пережившие находки (14 → 6 уникальных после дедупликации RU/EN-дублей)

| # | Severity | Ось | Файл | Суть | Диспозиция |
|:--|:--|:--|:--|:--|:--|
| F1 | **MED** | config-honesty | `app/Jobs/Procurement/ShadowAnalysisJob.php:68`, `app/Services/Llm/ShadowDispatch.php:57`, `config/horizon.php` | `onQueue('ai_shadow')` пушит в очередь, для которой в Horizon НЕТ супервайзера (есть только `default`/`doc_prepare`/`ai_screening`/`ai_extraction`) → shadow-job'ы молча копятся в Redis и не обрабатываются | **Owner disposition.** Вне scope DOMLANG (pre-existing EST-AI, ни одна фаза P1–P8 не трогала). Не reopen. Срабатывает только при активном shadow-route (возможно, dormant) |
| F2 | LOW | dead-prefix (код) | `app/Services/Procurement/CrawlerRunManager.php:16` | Docblock называет индекс `crw_runs_crawler_id_running_unique`, реальное имя — `prc_crawler_runs_crawler_id_running_unique` (миграция `100039:43`). Мёртвый `crw_`-префикс в комментарии, DB-объекта под таким именем нет | Follow-up (plan-hygiene). Pre-existing, вне scope P3 (ренейм колонок, не комментариев) и P6 (docs-only, D29). Косметика, runtime-эффекта нет |
| F3 | LOW | dead-prefix (тесты) | `tests/Feature/Procurement/AiAnalysisTest.php:18,68`, `tests/Feature/Crawler/CsvBlockIngestorTest.php:104,144`, `tests/Feature/Procurement/DocumentVersioningTest.php:35` | Имена тестов описывают поведение против несуществующих v2-имён (`ai_dataset_examples`/`est_estimates`/`ai_eval_runs`/`ai_analyses`/`crw_fetches`/`crw_ignored_items`/`doc_documents`) | Follow-up. Pre-existing (git blame → `e98521b`/`9c3466e`), не воскрешены, новых нет. Вне scope всех фаз (P3.3 zero-residue грепал только `plt_`/`source_*` по D22) |
| F4 | LOW | brief-vs-code | `app/Services/Procurement/PipelineDispatcher.php` | Формулировка focus-брифа «PipelineDispatcher мутирует статус документа» — это **до-D25 редакция**. Отгруженный код намеренно НЕ мутирует `prc_documents.status` (явный docblock); переход `downloaded→prepared` и инвариант «один job на документ» держит `PrepareDocumentJob` (`ShouldBeUnique` + status-guard + lease + `uniqueFor()` TTL, ADR-0009) | Не дефект — код корректен, расхождение с формулировкой брифа. Зафиксировано, чтобы бриф не воспринимался как несоответствие |
| F5 | LOW | resource-growth | `app/Services/Procurement/StatusMappingService.php:20` | `resolve()` делает `firstOrCreate(registry_id, source_stage)` на сыром ярлыке этапа из внешнего ЕИС-фида → фид с многими distinct-строками растит `prc_status_mappings` по строке на каждую. Без SQL-инъекции (Eloquent-bound) | Не дефект — pre-existing intended (V2-CORE D5: неизвестная пара → `unknown` + admin, не краш). `StatusMappingSeeder` (P2.2) делает auto-vivify редким. Митигация уже частично есть (`pipeline:stuck-check unknown_spike_threshold`) |
| F6 | LOW | confirmation | `plans/DOMLANG/plan.md`, `docs/03_Dev/ocds-mapping.md`, `database/migrations/…100011`, `database/seeders/StatusMappingSeeder.php` | 6 находок-подтверждений «чисто»: связность целостна · security чист · OCDS+ADR согласованы · схемный ренейм сквозной · seeder сидирует реальные пары · provenance thin slice | Действий не требует |

---

## Независимая реконсиляция (P9.1 Code Guidance — не доверять харнессу вслепую)

Прогнаны контрольные грепы; расхождений харнесс↔греп, требующих эскалации, нет:

| Проверка | Результат | Вывод |
|:--|:--|:--|
| dead-prefix в `app/`+`database/migrations/` (D32-исключения: очереди Horizon, `ai_route`-permissions, `sys_get_temp_dir`) | 2 строки residue | обе легитимны/pre-existing: `CrawlerRunManager.php:16` (= F2, харнесс её тоже поймал — расхождения нет) и `100025:45` (комментарий «перенос из v1 `plt_document_analysis`» — v1-carryover, зафиксирован в P3.3 CN, класс KEEP-v1) |
| ADR-0006: rejected-context каталоги (`SourceIntake`/`NoticeEnrichment`/`DocumentLifecycle`/`EstimateIntelligence`/`AiOrchestration`) в `app/` | 0 | namespace-ренейм НЕ начат — подомены остаются папками ✓ |
| ADR-0008: спекулятивные колонки (`provenance_source`/`provenance_level`/`analysis_confidence`) вне `plans/` | 0 | thin slice не нарушен ✓ |
| `ProcurementStatus::Announced` в `app/`+`database/`+`tests/` | 0 | удалён без residue (P2.1) ✓ |
| glossary spot-check (`prc_procurements` в миграциях ∧ `procurement` в `docs/02_Workflow/glossary.md`) | оба present | глоссарий↔код согласованы ✓ |
| F1 (`ai_shadow` без супервайзера) — независимая проверка `grep ai_shadow config/` | 0 совпадений | находка **CONFIRMED**: Horizon обрабатывает только явно перечисленные очереди |

**Baseline residue-грепа:** `findings/P6-drift-map.md` покрывает `docs/**` (KEEP-v1 ∪ N/A = 42 строки).
Найденные в коде residue (F2, `100025:45`) в этот baseline не входят — но и не являются DOMLANG-регрессией:
P3 = ренейм колонок, P6 = docs-only (D29); код-комментарии и имена тестов не были в scope ни одной фазы.

---

## Reopen-маршрутизация (D37)

**Reopen-тикетов нет.** Ни одна пережившая находка не достигла HIGH/CRITICAL. Диспозиция:

- **F1 (MED)** — owner-disposition item, НЕ reopen: вне scope DOMLANG (pre-existing EST-AI-код,
  ShadowAnalysisJob/ShadowDispatch/horizon.php не трогала ни одна фаза P1–P8). Рекомендация владельцу:
  либо завести супервайзер `ai_shadow` в `config/horizon.php`, либо гейтить shadow-route с
  config-honesty-нотой. Класс — вне DOMLANG, наравне с deferred D26 (handoff).
- **F2–F5 (LOW)** — follow-ups plan-hygiene/техдолга для владельца на plan-close; не reopen, не
  DOMLANG-регрессия. F4 — расхождение формулировки, а не кода (код корректен по ADR-0009).
- **F6 (LOW)** — подтверждения, действий не требуют.

Проходной для дальнейшего движения плана: gate = PASS, 0 HIGH/CRITICAL → P9 продолжается (P9.2).
