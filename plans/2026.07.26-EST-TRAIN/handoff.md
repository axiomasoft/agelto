# HANDOFF — 2026-07-28 — after P5.4

**Next:** exec-items: task:plan-exec 2026.07.26-EST-TRAIN P5.5 P5.6

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — прописанная реализация поверх зафиксированных решений (D19, D43–D45) и готовых `ai:gold:build`/`--screening` P5.3/P5.4, открытых design-решений нет |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Остаток батча B3: `ai:gold:import` (рабочие копии Document/DocumentVersion, подготовка P3, записи датасета, P5.5) → `ai:eval:run --task=screening` + `ScreeningEvalMetrics` (P5.6). **Датасет `task=screening` неполон** — см. Open risks, читать ПЕРЕД стартом |

```
/task:plan-exec 2026.07.26-EST-TRAIN P5.5 P5.6
```

**Done:** P1 — 🟢 4/4. P2 — 🟠 Done with deviations (2/4 🟢, 2/4 🟠). P3 — 🟠 Done with deviations,
терминальна (2/9 🟢: P3.8, P3.9; 7/9 🟠: P3.1–P3.7). P4 — 🟠 Done with deviations, терминальна
(3/5 🟢; 2/5 🟠: P4.4, P4.5). P5 — 🟡 In progress (4/7, 1 🟠): P5.1/P5.2/P5.7 — 🟢; **P5.3 🟢** —
`ai:gold:build` (6 построено/4 понижено/14 пропущено/0 отклонено, byte-identical, манифест 4 поля,
`ai:fixtures:verify` 24/24); **P5.4 🟠 Done with deviations** — ветка `--screening` +
`ScreeningGoldBuilder` + §8.5 спеки реализованы по ТЗ и зелены на тестах (6/6), но реальный прогон
`ai:gold:build --screening`: `построено 11 · отклонено 13` — ВСЕ 11 `class=est` построены, ВСЕ 13
негативов отклонены (`Fixture.doc_type IS NULL` у каждого — реестр обязан его нести, P5.4 не имеет
права заполнять/расширять `AiDocumentType`, Code Guidance item'а) — коммит `3186fa0`.

**Remaining:** P5.5–P5.6 (батч B3, остаток) — входы готовы (11 extraction-gold + 11 screening-gold,
§8.5, `ScreeningResult`/`AiRouteResolver`). P6–P9 — не начаты (0/3, 0/6, 0/5, 0/6). Состав плана —
49 items.

**Open risks:**
- **БЛОКЕР `doc_type` (обнаружен P5.4, НЕ устранён — вне Scope P5.4/P5.5/P5.6):** `prc_ai_fixtures.
  doc_type` пуст у ВСЕХ 13 негативов корпуса. Следствие для P5.5: `ai:gold:import` создаст
  `task=screening` записи ТОЛЬКО на 11 позитивов — датасет непустой (Validation P5.5 формально
  зелёный), но однобокий. Следствие для P5.6: `ScreeningEvalMetrics` сможет посчитать `accuracy`/
  `false_negative_rate` по 11 сметам, но `false_positive_rate` — ГЛАВНОЕ число D5 («ложное
  срабатывание дороже пропуска») — будет вычисляться на ПУСТОМ множестве негативов (0/0 или
  не определено): метрика технически не сломается, но не измерит то, ради чего вся ветка задумана,
  пока владелец не заполнит `doc_type` (кандидаты в `Unknown` почти для всех, `ContractDraft` для
  `NEG-DOCX-CONTRACT-001` — разбор в `phases/P5.md` P5.4 Pending Work) и не перезапустится
  `ai:gold:build --screening` (без правки кода — чисто данными реестра).
- **Инфраструктура (починено P5.3, не требует повтора):** `plans/2026.07.26-EST-TRAIN/` несёт
  default ACL для `www-data` (`setfacl -R -m u:www-data:rwx` + `-d`).
- **`docker compose` недоступен в сессии**: обход — `docker exec agelto_app …`.
- Посторонний незакоммиченный дифф вне items P1–P5.4 (без изменений этим заходом): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- Остаточные WARN плана (`plan-lint`) — счёт с якорем и атрибуцией K в Completion Notes P5.3/P5.4.
- `phpstan-baseline.neon` правится вне `Files` — кандидат в отдельный item `@property`-аннотаций.
- Docs-sync follow-up (унаследован от P3) и долг доопределения `prc_estimate_totals.kind` по
  `label`/`@Caption` (`research/06` §7) — владелец не назван.

**Workarounds / Deferred / Open questions:** OQ-3 (боевой прогон `ai:eval:run` обеих задач гейтится
доступом к провайдеру) — P5.1–P5.4 исполняются без него; OQ-4 фазу не блокирует.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P5.md` (P5.4 закрыт, P5.5 next; блокер `doc_type` — P5.4 Pending
Work) · `plans/2026.07.26-EST-TRAIN/research/02-fixture-shortlist.md` (разбор негативов) ·
`docs/specs/v2/08-estimate-extraction.md` §8.5 · `plans/2026.07.26-EST-TRAIN/roadmap.md` (49 строк,
батч B3) · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
