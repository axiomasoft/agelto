# HANDOFF — 2026-07-10 — after P0.0

**Next:** автопрогон P1→P3 через runner плана (встанет на phase-гейте после P3)

| Параметр | Значение |
|:--|:--|
| Model | лейны внутри runner'а по §3 (P1.1 haiku/low · P1.2–P1.3 opus/xhigh · P2–P3 sonnet/medium) |
| Thinking | по лейну пункта |
| Context | fresh session |
| Суть | Фазы P1–P3: карта имён → ADR → pre-mortem → сиды статусов → семантический ренейм колонок |

````
Запусти Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phases: 'all' } }) — автопрогон плана DOMLANG с per-item review и phase-review веером. Runner дойдёт до конца P3 и ОСТАНОВИТСЯ на phase-гейте (D10): ренейм колонок затронул 341 PHP-файл и 99 тестов, нужен взгляд на `git diff` до того, как P4-P8 начнут на него опираться. Возобновление — командой из его же вывода (resumeWith). Может встать раньше: hard-гейт P3.1 (миграции уже в main) или эскалация исполнителя.
````

Альтернативы: одна фаза — `args: { phase: 'P1' }`; один пункт без runner'а —
`/task:plan-exec DOMLANG P1.1` (haiku/low; cold-start reads: `plans/DOMLANG/plan.md`,
`phases/P1.md`, `findings/P1-audit-verification.md`).

**Done:** План DOMLANG создан из одобренного черновика `plans/domlang-draft.md` (удалён, история в
git). Внешний аудит верифицирован против кода и через RAG — выжимка в
`findings/P1-audit-verification.md`. Приняты решения D1–D9. ACTIVE переключён EST-AI → DOMLANG.
Фаза P1 детализирована (3 items, DoR пройден); P2–P9 — скелеты со скелет-маркером.

**Remaining:** 22 items в 9 фазах, 0 закрыто. Фаза P1 (дизайн) → P2–P5 (код) → P6–P8 (доки) →
P9 (финальное ревью). Порядок неизменен (D7): код перед документацией.

**Sources of truth:** `plans/DOMLANG/plan.md` · `plans/DOMLANG/phases/P1.md` ·
`plans/DOMLANG/findings/P1-audit-verification.md` · `database/migrations/**` (канон имён до
закрытия P6) · `docs/03_Dev/adr/` · `docs-private/risk-register.md`

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` — ненадёжный источник имён таблиц (166 вхождений мёртвых
  префиксов). Исполнители P2–P5 обязаны читать `findings/P1-actual-names.md`, а не спеки.
- P3 правит опубликованные миграции на месте. Гейт: миграции не должны быть в `main`. Проверяется
  командой в P3.1, не памятью. Pre-mortem — P1.3.
- `prc_status_mappings` не сидируется: до закрытия P2 любой боевой прогон уводит все закупки в
  `canonical_status = 'unknown'`.
- `PipelineDispatcher` не мутирует статус документа (D17 EST-AI не выполнен буквально) — нет
  защиты от двойной постановки в очередь. Чинится в P5.1.
- Модели Gemini 2.5 несут `eol_date = 2026-10-16` (D27 EST-AI) — вне периметра этого плана, но
  часы тикают.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: остальные пункты `docs-private/open-follow-ups.md` (`prc_protocols.document_version_id`,
  `clarifications`/`cancellations`, multi-day update-window, `Procurement.crawler_id`) — вне плана,
  нет входного контракта источника. Реализация OCDS-экспортера (P7.2 даёт только mapping-таблицу).
- open_questions: 4 открытых — `plans/DOMLANG/open-questions.md`. Q3 (`etp_number`: заводить сразу
  или ждать источник) требует решения владельца и разбирается в pre-mortem'е P1.3.
