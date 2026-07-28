# HANDOFF — 2026-07-28 — after P5.6

**Next:** phase-close: `/task:plan-close 2026.07.26-EST-TRAIN P5`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — сверка терминальных статусов items по факту, закрытие фазы, не design-решение |
| Capabilities | — |
| Context | NEW SESSION — шаг-не-item (`plan-close` читает план с диска заново) |
| Суть | Фаза P5 (7/7 items терминальны: 5 🟢, 2 🟠) готова к закрытию — таблицы Phase Status/Status Board уже актуальны (эта сессия), `plan-close P5` сверяет по git-фактам, пишет Phase Handoff, актуализирует `## 4. Phase Index & Status Board`/roadmap и решает статус фазы (🟠 Done with deviations, т.к. 2 items 🟠) |

```
/task:plan-close 2026.07.26-EST-TRAIN P5
```

**Done:** P1 — 🟢 4/4. P2 — 🟠 Done with deviations. P3 — 🟠 Done with deviations, терминальна.
P4 — 🟠 Done with deviations, терминальна. **P5 — 7/7 items терминальны** (P5.1/P5.2/P5.3/P5.6/
P5.7 — 🟢; P5.4/P5.5 — 🟠 Done with deviations) — фаза САМА ещё не закрыта командой `plan-close`
(таблицы item-уровня актуальны, но `Phase Handoff`-нарратив и роадмап это не обновляет — не Scope
`plan-exec`). Батч B3 (P5.3–P5.6) полностью исполнен этим заходом (четыре item'а, три коммит-пары):
- **P5.3 🟢** — `ai:gold:build`: `GoldBuilder` + команда, реальный прогон 6 построено/4 понижено/
  14 пропущено/0 отклонено, байт-идентично, `--update-manifest` меняет ровно 4 поля,
  `ai:fixtures:verify` 24/24.
- **P5.4 🟠** — ветка `--screening` + `ScreeningGoldBuilder` + §8.5: 11 построено (все `class=est`)
  / 13 отклонено — реестр `doc_type` пуст у ВСЕХ негативов (вне Scope, решение владельца).
- **P5.5 🟠** — `ai:gold:import`: 24 документа/28 версий, 6+11 записей датасета, идемпотентно;
  `--prepare` полным прогоном OOM в P3 `SpreadsheetStrategy` на 2 книгах даже на 1024M (вне Scope,
  обход — поэлементный `--code`); `soffice` PATH-квirk в дочернем процессе (P3, вне Scope).
- **P5.6 🟢** — ветка `--task=screening` в `ai:eval:run` + `ScreeningEvalMetrics` (accuracy/
  confusion/FP/FN, независим от extraction `EvalMetrics`, не тронутого), §8.5 дополнен абзацем про
  раннер; боевой прогон гейтится OQ-3 (штатно, Scope Excluded), `--dry-run` на реальном корпусе
  подтверждён.

**Remaining:** P6–P9 — не начаты (0/3, 0/6, 0/5, 0/6). Состав плана — 49 items. Фаза P5 ждёт
`plan-close`.

**Open risks:**
- **БЛОКЕР `doc_type` (P5.4, НЕ устранён, накопительный эффект на P5.5/P5.6):** 13/13 негативов
  корпуса несут `prc_ai_fixtures.doc_type = NULL`. Датасет `task=screening` — 11 позитивов, 0
  негативов. `false_positive_rate` (D5, главное число всей ветки скрининга) недоступен для
  измерения на реальных данных, пока владелец не заполнит `doc_type` (кандидаты — `Unknown` почти
  для всех 12, `ContractDraft` для `NEG-DOCX-CONTRACT-001`, разбор — `phases/P5.md` P5.4 Pending
  Work) и не перезапустит `ai:gold:build --screening` → `ai:gold:import`.
- **P3 OOM на `--prepare` (P5.5, НЕ устранён, вне Scope):** `EST-XLS-KS2-001`/`EST-XLSX-LSR-002`
  крашат `SpreadsheetStrategy` полным прогоном даже на `memory_limit=1024M`; поэлементный `--code` —
  рабочий обход. Кандидат в отдельный item (профилировка P3/P6).
- **`soffice` PATH-квirk (P5.5, НЕ устранён, вне Scope):** `LibreOfficeConverter` не наследует PATH
  дочернего процесса — `sh: 1: exec: soffice: not found` при установленном и рабочем бинаре.
- **Инфраструктура (починено, не требует повтора):** `plans/2026.07.26-EST-TRAIN/`, `app/`,
  `tests/`, `phpstan-baseline.neon` несут ACL для `www-data` (`setfacl -R -m u:www-data:rwx` + `-d`).
- **`docker compose` недоступен в сессии**: обход — `docker exec agelto_app …`.
- Посторонний незакоммиченный дифф вне items P1–P5.6 (без изменений этим заходом): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- `phpstan-baseline.neon` растёт вне `Files` в трёх items подряд (P5.3/P5.5/P5.6) — модели `Document`/
  `Analysis`/`EvalRun` без `@property`-аннотаций, кандидат в отдельный item (см. Pending Work P5.3).
- Docs-sync follow-up (унаследован от P3) и долг доопределения `prc_estimate_totals.kind` по
  `label`/`@Caption` (`research/06` §7) — владелец не назван.

**Workarounds / Deferred / Open questions:** OQ-3 (боевой прогон `ai:eval:run` обеих задач гейтится
доступом к провайдеру) — вся фаза P5 исполнена без него; OQ-4 фазу не блокировала.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P5.md` (все 7 items терминальны, Phase Handoff ждёт `plan-close`) ·
`docs/specs/v2/08-estimate-extraction.md` §8.2/§8.5 · `plans/2026.07.26-EST-TRAIN/roadmap.md`
(49 строк) · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
