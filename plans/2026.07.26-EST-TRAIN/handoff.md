# HANDOFF — 2026-07-28 — after P7

**Next:** `/task:plan-audit 2026.07.26-EST-TRAIN P7`

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | xhigh — фаза закрыта НЕ чисто (пять `🟠 Done with deviations`), правило launch-block'а требует аудита перед переходом к P8 |
| Context | NEW SESSION — шаг-не-item |
| Суть | Adversarial-аудит закрытой фазы P7: сверка пяти `🟠`-items (P7.1–P7.5) с git-фактами/кодом, легальность P7.6 `⬜` (D53/D57), no-op/scope-drift, handoff |

```
/task:plan-audit 2026.07.26-EST-TRAIN P7
```

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5/P6 — 🟠 терминальны. **P7 — 🟠 терминальна (5/6:
P7.1–P7.5 🟠, P7.6 легально `⬜ Not started`)** — три сквозных и пять пошаговых промта доведены
против корпуса (свидетель на каждое правило/синоним в `research/13`), `EvalMetrics` +
`EstimateDiffReport`/`ai:eval:diff` расширены, очередь `prc_ai_prompt_suggestions` +
`ai:prompts:suggest`/`ai:prompts:apply` + `LlmPurpose::PromptCritic` заведены, протокол тюнинга
(`tune`/`holdout`, критерий приёмки, `ai:eval:compare`) зафиксирован ДО первого прогона с данными.
P8 (0/5), P9 (0/6) — не начаты, но уже полностью детализированы (`roadmap.md`).

**P7.6 (2026-07-28, sonnet/low, plan-close reconciliation):** OQ-3 снят (D122), апрув цены цикла
ПОЛУЧЕН от владельца (Dmitry Vostrikov, «Да, до $5», оценка по трекингу ≈$1.0/потолок $3–5 —
методика P6.1: `token_estimate` × цена активной модели + наблюдённый ранее разрыв факт/трекинг),
но исполнение боевого цикла явным решением владельца ОТЛОЖЕНО: рабочий процесс сейчас — владелец
вручную тестирует промты на реальных документах вне плана и указывает точечные правки (механика
P7.1/P7.2), а не автоматический критик. Ни одного боевого LLM-вызова в рамках P7.6 не сделано, деньги
не потрачены. Item остаётся `⬜ Not started` легально — та же конструкция, что P6.1↔P6.2 (D53/D57):
фаза закрывается пятью терминальными items, шестой ждёт владельца. Апрув цены ($5) действителен
только для ОДНОГО полного прохода цикла (базовый + повторный прогон `--split=all`) на маршруте и
ценах даты запуска — расширение требует нового апрува. Детали — `phases/P7.md` P7.6 Completion
Notes/Pending Work и Phase Handoff.

**Remaining:** P7.6 — ждёт владельца (`waiting:` внутри `phases/P7.md`, не отдельный `Next` этого
файла — фаза уже закрыта, P7.6 не блокирует P8). Перед стартом P8 — `/task:plan-audit P7` (фаза
закрыта не чисто). Follow-up вне этого плана (не блокируют P8/P9): (1) сверить реальный расход дня
P6.2 с биллингом Google Cloud/AI Studio; (2) откалибровать `FormatBenchRunner`/`page_count × 258`
под tile-based тарификацию PDF на 3.x; (3) если/когда P7.6 запускается — свежий минимальный
диагностический вызов перед прогоном, если с P6.2 прошло много времени.

**Open risks:**
- **`plan-lint` несёт 6 ERROR вне scope закрытия P7** (унаследованы от НЕЗАКОММИЧЕННОГО закрытия
  P6.2/P6.3 — `journal.jsonl:117-118` невалидные `result` значений `fixed`/`closed`; `phases/P6.md`
  — отсутствующее поле Pending Work у P6.2, формат Escalation Needed, отсутствующие коды
  `write-site: pre_mutation/pre_final` у P6.2/P6.3): не порождены этим закрытием и не относятся
  к `Files` items P7 — чинить их значило бы редактировать чужую сдачу задним числом. Владельцу —
  решить, кто и когда доводит закрытие P6 до линт-чистоты (не блокирует P8).
- **P7.6 не пройден — автоматический критик (`ai:prompts:suggest`/`ai:prompts:apply`) НЕ проверен
  на боевых данных.** Единственный проверенный канал правки промтов сейчас — ручной (P7.1/P7.2
  механика). P8.4 (экранная форма апрува патча) будет готова раньше, чем механизм, который она
  апрувит, доказан боевым прогоном — это ожидаемо (D71: кнопка тестируется на `FakeLlmProvider`,
  эксплуатация гейтится OQ-3/апрувом цены отдельно).
- **Реальный расход дня P6.2 НЕ сверен с биллингом Google** — у нас $2.30 по (исправленному)
  трекингу, у владельца ~$12 по факту. Не блокирует P8/P9.
- **`FormatBenchRunner` не откалиброван под 3.x-тарификацию PDF** (унаследовано P6.1/P6.2) —
  follow-up, не блокер.
- **`holdout` из двух кодов — сигнализация, а не статистика (D121).** Обязательно к прочтению
  ПЕРЕД фактическим прогоном P7.6 и перед P9, см. `research/14-prompt-tuning-protocol.md` §5.
- **`research/10-gold-corpus-report.md` дискредитирован** как источник состава пула — источник
  истины — реестр `prc_ai_fixtures`.
- **dev-контейнер:** bind-mount не даёт `www-data` право записи в `resources/ai/prompts/**`;
  `docker compose exec` в этом окружении падает на интерполяции `.env` (`MINIO_ROOT_PASSWORD`),
  рабочий обход — `docker exec agelto_app` по имени контейнера.
- **Долг:** `PromptsSuggestCommand` дублирует резолв `Analysis`/gold-файла из `EvalDiffCommand`.
- **Долг D54 (P7.1+P7.2) частично закрыт P6.3:** ADR несёт раздел «Когда решение недействительно»
  со строкой про материальную смену набора промтов — сам перезамер остаётся будущей работой.
- **Словари `#[Alias]` не доезжают до модели на боевых вызовах** (Pending P7.1 №2) — fix-item.
- **Словарь `ModifierKind` не сделан** (Pending P7.1 №1) — ре-дизайн follow-up.
- **Позиционное сопоставление смет (`ordinal`)** (Pending P7.3) — не реализовано.
- Семантический дрейф `row_index` ↔ `source_position_number` в `EstimateHeaderMap` (v1-алиас).
- **БЛОКЕР `doc_type` (P5.4, унаследован):** `false_positive_rate` скрининга не измерим.
- **P3 OOM на `--prepare` (P5.5, унаследован).**
- `soffice` PATH-квирк (P5.5, унаследован) — в текущем контейнере работает.
- Посторонний незакоммиченный дифф в дереве (не мой, не трогал; `git status --short` на момент
  закрытия P7): `.gitignore`, `app/Models/Procurement/Ai/Fixture.php`, `plans/archive/2026.07.31-SYS-INTEGRITY/artifacts/gemini-analys.md`,
  `.swissknife/`.
- `phpstan-baseline.neon` растёт вне items — долг унаследован.
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 (доступ) — `Resolved → D122`. OQ-4 (переход на
3.x) — закрыт реопеном D123. Кандидат в новый OQ (линейка 2.5 может однажды снова заработать) —
остаётся открытым, вне P7.6. Кандидат в OQ (из D126): нужен ли owner-level процесс периодической
сверки трекинга vs биллинга — не решался.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D127, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (терминальна 5/6, P7.1–P7.6 Completion Notes/Phase
Handoff) · `plans/2026.07.26-EST-TRAIN/research/13-prompt-corpus-rules.md` ·
`plans/2026.07.26-EST-TRAIN/research/14-prompt-tuning-protocol.md` ·
`plans/2026.07.26-EST-TRAIN/research/18-holdout-split-rule.md` · `estimates/eval-splits.json` ·
`docs/specs/v2/08-estimate-extraction.md` §2.1/§2.2/§5.1/§5.4/§6.1/§8.4/§8.6 ·
`resources/ai/prompts/**` (восемь + критик) · `app/Services/Procurement/Extraction/EvalMetrics.php`,
`EstimateDiffReport.php` · `app/Console/Commands/Ai/{EvalDiffCommand,PromptsSuggestCommand,
PromptsApplyCommand,EvalCompareCommand}.php` ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` · `plans/2026.07.26-EST-TRAIN/roadmap.md` ·
`plans/2026.07.26-EST-TRAIN/journal.jsonl`.
