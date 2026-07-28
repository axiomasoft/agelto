# HANDOFF — 2026-07-28 — after P6.1

**Next:** ЗАПУСК ВРУЧНУЮ: frontier/high

| Параметр | Значение |
|:--|:--|
| Model | fable\|opus |
| Thinking | high — тексты промтов и словари синонимов против фактов корпуса, открытое design-решение |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P6.1 закрыт 🟢. P6.2/P6.3 гейтятся неразрешённым OQ-3 (доступ к провайдеру из РФ — Decision
pending у владельца) и остаются `⬜ Not started` (D53 — здоровое ожидание, не блокер плана). План
продолжается фазой P7 на БАЗОВОЙ политике представлений: следующий АКТИВНЫЙ item — **P7.1**
(`Exec = manual`, `frontier/high`, `plan.md` §3) — сквозные промты `global`/`extraction:global`/
`screening:global` против фактов корпуса |

```
Открой plans/2026.07.26-EST-TRAIN/phases/P7.md, раздел ### P7.1 — Сквозные промты и словари
синонимов против фактов корпуса. Исполни item строго по его Intent/Scope Included/Files/
Implementation Rules/Validation/Deliverables. Прочитай Required Reads item'а и Phase Context P7
перед началом. По завершении — Completion Notes, Pending Work, Known Deviations, Escalation
Needed, гейт закрытия (plan-lint --baseline HEAD, счёт с атрибуцией) и запись в journal.jsonl
(--command plan-run --actor frontier/high --result <статус>), затем перезапиши handoff.md по
канону `plan-protocol` §8.
```

Альтернатива (владелец разрешил OQ-3 первым): `/task:plan-run 2026.07.26-EST-TRAIN P6.2` под
`implementation/medium` — см. `waiting:`-строку в Open risks ниже, гейт доступа — первый шаг
Implementation Rules P6.2.

**Done:** P1 — 🟢 4/4, терминальна. P2 — 🟠 Done with deviations, терминальна. P3 — 🟠 Done with
deviations, терминальна. P4 — 🟠 Done with deviations, терминальна. P5 — 🟠 Done with deviations,
терминальна. **P6.1 — 🟢 Done** (1/3): `ai:bench:formats` + `FormatBenchRunner` + `BenchCellResult`;
кейс `RepresentationKind::PdfAlt` и его производство бенчмарком через `LibreOfficeConverter` (D49);
сейм принуждения `?RepresentationKind $forceKind = null` в `RepresentationResolver::forLlm()` и
`ExtractionOrchestrator::run()`/`resolveEffectiveFile()` (дефолт `null` — поведение байт-в-байт,
подтверждено регрессом `--filter=Extraction`/`--filter=Screening`, 46+47 passed). Сухой прогон
РЕАЛЬНОГО корпуса (`--dry-run`, 0 LLM-вызовов): 42 ячейки (6 эталонов × 7 вариантов), 22
`applicable=true` — `artifacts/bench-formats-dry-2026-07-28.json`; повтор байт-идентичен (кроме
`generated_at`). Методика и критерий победителя зафиксированы ДО данных —
`research/11-format-bench-method.md` (ИТОГО предсказанного input-объёма по подвыборке —
534 118 микро-$ ≈ $0.53, это НЕ полная цена боевого прогона — только первый input, без completion-
токенов и множественных вызовов на документ). Item-коммит `3f015a7`.

**Remaining:** P6.2/P6.3 — `⬜ Not started`, гейт OQ-3 (см. Open risks). P7–P9 — не начаты
(0/6, 0/5, 0/6).

**Open risks:**
- **`waiting: OQ-3 разрешён → exec-items 2026.07.26-EST-TRAIN P6.2`** (D53/`open-questions.md` OQ-3,
  статус «Decision pending» у владельца) — до разрешения P6.2/P6.3 не исполняются; план ЗАКОННО
  продолжается P7 на базовой политике представлений (`RepresentationResolver::DEFAULT_PRIORITY`
  не тронута P6.1 — правило D3 в конфиг не пишется вслепую).
- **Предсказание `default`-варианта `EST-GSFX-LSR-001` заведомо грубое** (эвристика `strlen/4` на
  6,24 МБ бинарном gsfx-контейнере без `pdf`-представления) — задокументировано в `research/11` как
  наблюдение, на критерий победителя не влияет (ячейка структурно `unprocessable` в реальном
  прогоне — `resolveEffectiveFile()` пропускает только PDF/изображение).
- **БЛОКЕР `doc_type` (P5.4, унаследован, НЕ устранён):** 13/13 негативов корпуса без `doc_type`,
  `false_positive_rate` ветки скрининга не измерим на реальных данных — решение владельца, вне
  Scope P5/P6.
- **P3 OOM на `--prepare` (P5.5, унаследован, НЕ устранён):** `EST-XLS-KS2-001`/`EST-XLSX-LSR-002`
  крашат `SpreadsheetStrategy` полным прогоном даже на `memory_limit=1024M`; поэлементный `--code` —
  рабочий обход.
- **`soffice` PATH-квirk (P5.5, унаследован, НЕ устранён):** `LibreOfficeConverter` не наследует
  PATH дочернего процесса в некоторых средах — P6.1 подтвердил рабочий вызов в ТЕКУЩЕМ контейнере
  (реальный `pdf_alt` произведён для 5/5 табличных эталонов сухим прогоном).
- Посторонний незакоммиченный дифф в дереве (без изменений этим item'ом, не мой): `.gitignore`
  (правка игнора `/estimates/files/`), `app/Models/Procurement/Ai/Fixture.php` (docblock
  `@property`), `estimates/gemini-analys.md`, `.swissknife/`.
- `phpstan-baseline.neon` — 10 новых записей от P6.1 (carve-out `plan-protocol` §6(b),
  детерминированная регенерация `--generate-baseline` над файлами `Files` item'а, не посторонний
  дифф); растёт и вне items P6 (модели без `@property`-аннотаций) — тот же унаследованный кандидат
  в отдельный item, что отмечен P5.3 Pending Work.
- Docs-sync follow-up (унаследован от P3) и долг доопределения `prc_estimate_totals.kind` по
  `label`/`@Caption` (`research/06` §7) — владелец не назван.

**Workarounds / Deferred / Open questions:** OQ-3 — см. Open risks (единственный гейт владельца
фазы P6, операционный: доступ к провайдеру + апрув предсказанной цены прогона P6.2). OQ-4 (дата
перехода на 3.x-модели) — Exploration, не блокирует P6.1/P7.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P6.md` (P6.1 Completion Notes, P6.2/P6.3 — детализированы,
ожидают OQ-3) · `plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.6 детализированы до DoR) ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3/OQ-4) ·
`plans/2026.07.26-EST-TRAIN/research/11-format-bench-method.md` ·
`docs/specs/v2/08-estimate-extraction.md` §8.2/§8.5 · `plans/2026.07.26-EST-TRAIN/roadmap.md` ·
`plans/2026.07.26-EST-TRAIN/journal.jsonl`.
