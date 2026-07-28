# HANDOFF — 2026-07-28 — after P5.5

**Next:** exec-items: task:plan-exec 2026.07.26-EST-TRAIN P5.6

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — прописанная реализация поверх зафиксированных решений (D5, D38, D44) и готового датасета P5.5, открытых design-решений нет |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Последний item батча B3: ветка `--task=screening` в `EvalRunCommand` + новый класс `ScreeningEvalMetrics` (accuracy/confusion/FP/FN), абзац про раннер в §8.5. **Датасет `task=screening` несёт 0 негативов** — FP-метрика (главное число D5) будет вычисляться на пустом множестве, читать Open risks ПЕРЕД стартом |

```
/task:plan-exec 2026.07.26-EST-TRAIN P5.6
```

**Done:** P1 — 🟢 4/4. P2 — 🟠 Done with deviations (2/4 🟢, 2/4 🟠). P3 — 🟠 Done with deviations,
терминальна. P4 — 🟠 Done with deviations, терминальна. P5 — 🟡 In progress (5/7, 2 🟠): P5.1/P5.2/
P5.7 — 🟢; P5.3 — 🟢 (`ai:gold:build`); **P5.4 🟠** — ветка `--screening` (11 построено/13
отклонено — `doc_type` негативов пуст в реестре, вне Scope); **P5.5 🟠** — `ai:gold:import`: 24
документа/28 версий (+4 gsfx-вложения), 6 extraction + 11 screening записей датасета, идемпотентно;
`--prepare` полным прогоном OOM-крашит на `EST-XLS-KS2-001`/`EST-XLSX-LSR-002` внутри P3
`SpreadsheetStrategy` даже на `memory_limit=1024M` — обход поэлементным `--code` (идемпотентно, тот
же итог); `LibreOfficeConverter` не находит `soffice` в PATH дочернего процесса, хотя бинарь
установлен (`ai:tools:doctor` → ok) — коммит `13671bf`.

**Remaining:** P5.6 (последний item батча B3) — входы готовы (11 extraction + 11 screening
записей, `ScreeningProcessor`/`AiRouteResolver`, `EvalMetrics` как образец класса — НЕ трогается).
P6–P9 — не начаты (0/3, 0/6, 0/5, 0/6). Состав плана — 49 items.

**Open risks:**
- **БЛОКЕР `doc_type` (P5.4, НЕ устранён):** датасет `task=screening` несёт ТОЛЬКО 11 позитивов, 0
  негативов. P5.6 сможет посчитать `accuracy`/`false_negative_rate`, но `false_positive_rate` —
  ГЛАВНОЕ число D5 — на пустом множестве негативов (0/0). Это НЕ повод не закрывать P5.6: ветка и
  метрика реализуются по ТЗ и корректно печатают `n/a`/`0` там, где данных нет; боевая ценность числа
  восстанавливается ПОСЛЕ того, как владелец заполнит `prc_ai_fixtures.doc_type` у 13 негативов
  (кандидаты в `Unknown` почти для всех, `ContractDraft` для `NEG-DOCX-CONTRACT-001` —
  `phases/P5.md` P5.4 Pending Work) и перезапустит `ai:gold:build --screening` → `ai:gold:import`.
- **P3 OOM на `--prepare` (P5.5, НЕ устранён — вне Scope батча B3):** `EST-XLS-KS2-001`/
  `EST-XLSX-LSR-002` крашат `SpreadsheetStrategy` полным прогоном даже на 1024M; поэлементный
  `--code` — рабочий обход, не постоянное решение. Кандидат в отдельный item P3/P6 (профилировка).
- **`soffice` PATH-квirk (P5.5, НЕ устранён — вне Scope):** `LibreOfficeConverter` (P3) не наследует
  PATH дочернего процесса — `sh: 1: exec: soffice: not found` при установленном и рабочем бинаре.
- **Инфраструктура (починено, не требует повтора):** `plans/2026.07.26-EST-TRAIN/`, `app/`, `tests/`
  несут default ACL для `www-data` (`setfacl -R -m u:www-data:rwx` + `-d`).
- **`docker compose` недоступен в сессии**: обход — `docker exec agelto_app …`.
- Посторонний незакоммиченный дифф вне items P1–P5.5 (без изменений этим заходом): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- Остаточные WARN плана (`plan-lint`) — см. Completion Notes P5.3–P5.5.
- `phpstan-baseline.neon` растёт вне `Files` (2 новых записи `Document::$status`/`$last_error`,
  P5.5) — тот же класс долга `@property`-аннотаций, кандидат в отдельный item.
- Docs-sync follow-up (унаследован от P3) и долг доопределения `prc_estimate_totals.kind` по
  `label`/`@Caption` (`research/06` §7) — владелец не назван.

**Workarounds / Deferred / Open questions:** OQ-3 (боевой прогон `ai:eval:run` обеих задач гейтится
доступом к провайдеру) — P5.1–P5.5 исполняются без него; OQ-4 фазу не блокирует.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P5.md` (P5.5 закрыт, P5.6 next — последний item фазы) ·
`docs/specs/v2/08-estimate-extraction.md` §8.4/§8.5 · `plans/2026.07.26-EST-TRAIN/roadmap.md`
(49 строк, батч B3) · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
