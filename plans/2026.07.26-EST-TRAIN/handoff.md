# HANDOFF — 2026-07-28 — after P7.2

**Next:** manual: implementation/high

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | same-session — item |
| Суть | P7.2 закрыт 🟠 (пошаговые промты v2, research/13 «Пошаговые правила»). Следующий активный item — **P7.3** (`Exec = manual`, `implementation/high`, `plan.md` §3): расширение `EvalMetrics` по карте следствий P4.5 + сервис `EstimateDiffReport` + DTO Diff + команда `ai:eval:diff` + §8.6 спеки |

```
Открой plans/2026.07.26-EST-TRAIN/phases/P7.md, раздел ### P7.3 — Расширение `EvalMetrics` и
детерминированный diff-отчёт «gold vs предсказание». Исполни item строго по его Intent/Scope
Included/Files/Implementation Rules/Validation/Deliverables. Прочитай Required Reads item'а
(EvalMetrics.php и его тест целиком, EstimateValidator/ValidationReport, research/08 строки P7.3,
спека §8.2/§8.4/§6, EvalRunCommand, plan.md §5 D8/D38/D62) и Phase Context P7 перед началом. По
завершении — Completion Notes, Pending Work, Known Deviations, Escalation Needed, гейт закрытия
(plan-lint --baseline HEAD, счёт с атрибуцией) и запись в journal.jsonl (--command plan-run
--actor implementation/high --result <статус>), затем перезапиши handoff.md по канону
`plan-protocol` §8.
```

Альтернатива (владелец разрешил OQ-3 первым): `/task:plan-run 2026.07.26-EST-TRAIN P6.2` под
`implementation/medium` — см. `waiting:`-строку в Open risks ниже.

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 1/3 (P6.1 🟢; P6.2/P6.3
ждут OQ-3, D53). **P7.1, P7.2 — 🟠 Done with deviations** (P7: 0/6 🟢, 2 items терминальны).
P7.2: пять пошаговых промтов доведены (`version` 1→2): screening:classify — 8 пограничных случаев
по фактическим позитивам/негативам корпуса; extraction:passport — реквизиты акта, `calc_method`
вне enum → null, трудозатраты из «Справочно», терминатор многосметного цикла (снят «№ п/п» —
Pending P7.1 №4); extraction:rows — якорение по смете дословной строкой research/07 §5.1,
печатный номер → `source_position_number`, КС-3 `summary_row` + три разреза, OCR «нечитаемое →
null», структурный вход; extraction:totals — «итоги сметы, не раздела», ставка → `rate` без
вычисления, подвал запрошенной сметы; extraction:discovery — два маркера на одной странице,
подписной блок, нечитаемость — не граница. research/13 «Пошаговые правила»: 24 свидетеля,
прочерков 0. Validation: PromptComposition 7 passed (3 новых кейса) / PromptSeeder 5 passed;
`ai:prompts:sync --check` — дрейфа нет; phpstan OK; pint PASS. Item-коммит `bff955b`.

**Remaining:** P7.3–P7.6 (P7.3/P7.4 — manual implementation/high; P7.5 — plan-exec; P7.6 гейт
OQ-3 + два апрува владельца). P6.2/P6.3 — гейт OQ-3. P8 (0/5), P9 (0/6) — не начаты.

**Open risks:**
- **`waiting: OQ-3 разрешён → exec-items 2026.07.26-EST-TRAIN P6.2`** (D53, Decision pending у
  владельца) — P6.2/P6.3/P7.6 не исполняются; план законно продолжается P7.3–P7.5 на базовой
  политике представлений.
- **Долг D54 (P7.1+P7.2):** версии восьми промтов подняты при несуществующем ADR — строка «набор
  промтов изменился → перезамер победителя» обязана появиться в ADR при его создании (P6.3);
  дословные формулировки — Pending Work P7.1/P7.2.
- **Словари `#[Alias]` не доезжают до модели на боевых вызовах** (Pending P7.1 №2): `compose()`
  получает только Response-класс шага (`RowRangeChunkSource.php:145`, `WindowedChunkSource.php:224`,
  `ScreeningProcessor.php:148`) — нужен fix-item через `plan-design` (вне Scope P7.1/P7.2).
- **Словарь `ModifierKind` не сделан** (Pending P7.1 №1) — enum без `#[Alias]`, §2.3 спеки нет;
  ре-дизайн follow-up.
- Семантический дрейф `row_index` ↔ `source_position_number` в `EstimateHeaderMap` (v1-алиас
  «№ п/п»; состав полей — P4.5/D33). В текстах промтов «№ п/п» больше нет (тест-кейс P7.2).
- **БЛОКЕР `doc_type` (P5.4, унаследован):** 13/13 негативов корпуса без `doc_type` —
  `false_positive_rate` скрининга не измерим на реальных данных; решение владельца.
- **P3 OOM на `--prepare` (P5.5, унаследован):** `EST-XLS-KS2-001`/`EST-XLSX-LSR-002` крашат
  `SpreadsheetStrategy` полным прогоном; поэлементный `--code` — обход.
- `soffice` PATH-квирк (P5.5, унаследован) — в текущем контейнере работает (P6.1).
- Посторонний незакоммиченный дифф в дереве (не мой, не трогал): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- `phpstan-baseline.neon` растёт вне items (унаследованный кандидат в отдельный item, P5.3).
- plan-lint: 0 ERROR / 10 WARN, новых от диффа item'а 0 — те же 10 унаследованных WARN, что после
  P7.1 (ложноположительные срабатывания класса P5/P6).
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 — единственный гейт владельца P6/P7.6 (доступ к
провайдеру + апрув цены). OQ-4 (переход на 3.x-модели) — Exploration, не блокирует. Дословность
якорения P7.2 выдержана с регистровым отступлением «ПО СМЕТЕ» → «по смете» (конфликт с грепами
словаря Validation #4, «М» ⊂ «СМЕТЕ») — Known Deviations P7.2; якорная подфраза байт-идентична
research/07 и закреплена тестом. compose-CLI сессии падает на интерполяции `MINIO_ROOT_PASSWORD`
— команды гонялись `docker exec agelto_app …` (тот же контейнер).

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1/P7.2 Completion Notes/Pending Work; P7.3–P7.6
детализированы до DoR) · `plans/2026.07.26-EST-TRAIN/research/13-prompt-corpus-rules.md` ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3/OQ-4) ·
`docs/specs/v2/08-estimate-extraction.md` §2/§3/§6/§7 · `plans/2026.07.26-EST-TRAIN/roadmap.md` ·
`plans/2026.07.26-EST-TRAIN/journal.jsonl`.
