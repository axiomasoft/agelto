# HANDOFF — 2026-07-28 — after P6.3 (фаза P6 терминальна, D127)

**Next:** ЗАПУСК ВРУЧНУЮ: `task:plan-run 2026.07.26-EST-TRAIN P7.6` (implementation/medium, manual)
— замыкание петли тюнинга промтов. Гейты владельца — ПЕРВЫЙ шаг item'а (тот же паттерн, что P6.2):
OQ-3 уже снят (D122), но апрув цены КОНКРЕТНОГО прогона и апрув КОНКРЕТНОГО патча промта (D8) — нет,
их проходит исполнитель P7.6 по месту, не эта сессия.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписано §3 Routing (P7.6, implementation/medium, manual) |
| Context | continue (/clear) — ручной item |
| Суть | Боевое замыкание петли тюнинга промтов P7.5 (`estimates/eval-splits.json`, `ai:eval:run
--split=tune`) — первый шаг: гейт доступа + апрув цены + апрув конкретного предложенного патча
промта (D8), затем применение через `ai:prompts:apply` (P7.4) и отчёт цикла |

```
Исполни P7.6 плана 2026.07.26-EST-TRAIN по его ТЗ в plans/2026.07.26-EST-TRAIN/phases/P7.md.
Фаза P6 терминальна (3/3, D127) — вход P7.6 по phase-first порядку открыт. ПЕРВЫЙ шаг item'а —
гейт владельца (OQ-3 уже разрешён D122, но апрув цены и апрув конкретного патча — нет, читай
Implementation Rules P7.6 целиком).
```

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. **P6 — 🟠 терминальна (3/3: P6.1 🟠,
P6.2 🟠, P6.3 🟢)** — ADR `root/adr-input-representation.md` + `config('ai.representations')`
приняты, `RepresentationResolver` переведён на конфиг. P7.1–P7.5 — 🟠 Done with deviations
(терминальны), P7.6 — следующий item (был гейтирован терминальностью P6, теперь открыт). P8 (0/5),
P9 (0/6) — не начаты.

**P6.3 (закрыт 2026-07-28, sonnet/high, plan-run, D127):** Владелец выбрал «принять `pdf_alt` с
явной оговоркой» из трёх альтернатив (принять / отложить до нового прогона / временная базовая
политика) — критерий P6.1 механически не выделил строгого победителя (`research/12` §4), но
`pdf_alt` дисквалифицирован РОВНО одним эталоном (`EST-XLSX-KS2-001`, техническая причина —
token-limit харнесса, не качество представления) и дешевле обоих реальных конкурентов на остальных
пяти. Реализовано: ADR (все шесть обязательных разделов, цифры со ссылкой на ячейки `research/12`),
`config('ai.representations')` (`policy.xlsx`/`xls` = `[pdf_alt, pdf]`, `default` = `[pdf]`,
`fallback_to_source = true`), `RepresentationResolver::forLlm()` на конфиге (`DEFAULT_PRIORITY`
удалена), спека §5.4, ссылка на ADR в `research/12`. Тесты: `RepresentationPolicy` 5/5,
регресс `RepresentationContract`/`Extraction`/`Screening`/`BenchFormats` — все зелёные без правок
ожиданий. `phpstan`/`pint` чисто (точечная правка `phpstan-baseline.neon` — счётчик ignore-паттерна
1→2, второе легитимное чтение `$version->extension`). Детали — `phases/P6.md` P6.3 Completion Notes.

**Remaining:** P7.6 — следующий item, гейты владельца внутри него самого (не пройдены этой сессией).
Follow-up вне этого плана (не блокируют P7.6/P8/P9): (1) сверить реальный расход дня P6.2 с
биллингом Google Cloud/AI Studio и пересчитать экономику D123/D126 — приоритет высокий, деньги,
владелец решает; (2) откалибровать `FormatBenchRunner`/`page_count × 258` под tile-based
image-тарификацию PDF на 3.x (не блокирует принятое ADR-решение — калибровка меняла бы только
ПРЕДСКАЗАНИЕ объёма, не фактические цифры боевого прогона P6.2, на которых стоит ADR); (3) если
понадобится закрыть последний token-limit отказ подвыборки — ещё один шаг `maxTokens`
(≈49152–65536), не блокер (ADR уже принят).

**Open risks:**
- **Реальный расход дня P6.2 НЕ сверен с биллингом Google** — у нас $2.30 по (теперь исправленному
  вперёд) трекингу, у владельца ~$12 по факту. Разница объясняется багом D126 (исправлен), но точная
  итоговая сумма дня — только в консоли Google Cloud/AI Studio. Не блокирует P7.6/P8/P9.
- **`FormatBenchRunner` не откалиброван под 3.x-тарификацию PDF** (унаследовано P6.1/P6.2) — follow-up,
  не блокер: принятое ADR-решение стоит на ФАКТИЧЕСКИХ цифрах P6.2, не на предсказании.
- **`holdout` из двух кодов — сигнализация, а не статистика (D121).** Обязательно к прочтению
  ПЕРЕД P7.6/P9, см. `research/14-prompt-tuning-protocol.md` §5.
- **`research/10-gold-corpus-report.md` дискредитирован** как источник состава пула — источник
  истины — реестр `prc_ai_fixtures`.
- **dev-контейнер:** bind-mount не даёт `www-data` право записи в `resources/ai/prompts/**`;
  отдельно — `docker compose exec` в этом окружении падает на интерполяции `.env`
  (`MINIO_ROOT_PASSWORD`), рабочий обход — `docker exec agelto_app` по имени контейнера.
- **Долг:** `PromptsSuggestCommand` дублирует резолв `Analysis`/gold-файла из `EvalDiffCommand`.
- **Долг D54 (P7.1+P7.2) частично закрыт P6.3:** ADR теперь существует и несёт раздел «Когда решение
  недействительно» со строкой про материальную смену набора промтов — сам перезамер (если промты
  материально изменятся) остаётся будущей работой, не выполненной этим заходом.
- **Словари `#[Alias]` не доезжают до модели на боевых вызовах** (Pending P7.1 №2) — fix-item.
- **Словарь `ModifierKind` не сделан** (Pending P7.1 №1) — ре-дизайн follow-up.
- **Позиционное сопоставление смет (`ordinal`)** (Pending P7.3) — не реализовано.
- Семантический дрейф `row_index` ↔ `source_position_number` в `EstimateHeaderMap` (v1-алиас).
- **БЛОКЕР `doc_type` (P5.4, унаследован):** `false_positive_rate` скрининга не измерим.
- **P3 OOM на `--prepare` (P5.5, унаследован).**
- `soffice` PATH-квирк (P5.5, унаследован) — в текущем контейнере работает.
- Посторонний незакоммиченный дифф в дереве (не мой, не трогал): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- `phpstan-baseline.neon` растёт вне items — долг унаследован, не новый от этого захода (правка
  P6.3 — точечная, тот же класс, что регенерация P6.1, carve-out `plan-protocol` §6(b)).
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 (доступ) — `Resolved → D122`. OQ-4 (переход на
3.x) — закрыт реопеном D123, обязательство P6.3 (дата замера/модели/раздел недействительности в ADR)
выполнено. Кандидат в новый OQ (линейка 2.5 может однажды снова заработать — «возвращаться на 2.5
или остаться на 3.x» решает владелец новым D#) — остаётся открытым, вне P7.6. **Кандидат в OQ (из
D126):** нужен ли owner-level процесс периодической сверки трекинга vs биллинга — не решался, чисто
операционный вопрос, владелец решает приоритет.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D127, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P6.md` (терминальна, P6.3 Completion Notes/Phase Handoff) ·
`plans/2026.07.26-EST-TRAIN/root/adr-input-representation.md` (ADR, новое) ·
`plans/2026.07.26-EST-TRAIN/research/11-format-bench-method.md` (методика/критерий) ·
`plans/2026.07.26-EST-TRAIN/research/12-format-benchmark.md` (финал v3, ссылка на ADR добавлена) ·
`config/ai.php` (`representations` — новый блок) ·
`app/Services/Procurement/Prepare/RepresentationResolver.php` (читает конфиг, `DEFAULT_PRIORITY`
удалена) · `tests/Feature/Procurement/Prepare/RepresentationPolicyTest.php` (новое) ·
`docs/specs/v2/08-estimate-extraction.md` §5.4 (новое) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.5, P7.6 — следующий) ·
`plans/2026.07.26-EST-TRAIN/research/14-prompt-tuning-protocol.md` ·
`plans/2026.07.26-EST-TRAIN/research/18-holdout-split-rule.md` ·
`estimates/eval-splits.json` · `docs/specs/v2/08-estimate-extraction.md` §5.1/§5.4/§6.1/§8.4/§8.6 ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` · `plans/2026.07.26-EST-TRAIN/roadmap.md` ·
`plans/2026.07.26-EST-TRAIN/journal.jsonl`.
