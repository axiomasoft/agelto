# HANDOFF — 2026-07-28 — after P5.1

**P5.1 закрыт 🟢.** `App\Services\Procurement\Gold\GsfxGoldMapper` (`toGold()`/`unmappedReport()`)
и `App\Services\Procurement\Gold\GoldValidator` (дословный приём `DatasetImportCommand`) реализованы
по ТЗ, восстановленному D113 после реопена P3 (D110). На реальном эталоне `EST-GSFX-LSR-001`:
110 из 110 `<Position>` дали 110 строк `row_type=item`, `GoldValidator` — 0 ошибок. Найден и
устранён дефект нормализации, не покрытый ни одним Input: `Quantity="2E-5"` (научная нотация) ломала
`bcmath` в PHP 8.4 — добавлено безфлоатное разворачивание мантиссы/экспоненты. Детали — `phases/
P5.md` P5.1 Completion Notes.

**Next:** manual: implementation/high

| Параметр | Значение |
|:--|:--|
| Model class | implementation (sonnet) |
| Effort | high — гейт распознаваемости сетки (§3 Routing: анти-подгонка критерия, честное понижение `gold_source`) |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P5.2: `research/09-gold-grid-criterion.md` (методика ПЕРВЫМ) + `GridRecognitionGate` + `SheetGoldMapper` + `HeaderMapReader`/`GoldHeaderMap` — табличная книга → gold с порогами `0.90`/`3`, зафиксированными ДО прогона (D42) |

```
/task:plan-run 2026.07.26-EST-TRAIN P5.2
```

**Done:** P1 — 🟢 4/4. P2 — 🟠 Done with deviations (2/4 🟢, 2/4 🟠). P3 — 🟠 Done with deviations,
терминальна (2/9 🟢: P3.8, P3.9; 7/9 🟠: P3.1–P3.7). P4 — 🟠 Done with deviations, терминальна
(3/5 🟢: P4.1–P4.3; 2/5 🟠: P4.4, P4.5) — +29 колонок `prc_estimate*`, новая
`prc_estimate_work_types`, DTO/спека синхронизированы. P5 — 🟡 In progress (1/6): **P5.1 🟢** —
`.gsfx` → gold §8.2 закрыт по факту реального эталона.

**Remaining:** P5.2–P5.6 (табличная книга, сборка gold, screening-эталоны, ветка eval) — 5 items
⬜ Not started, блокировок нет; следующий — P5.2. P6–P9 — не начаты (0/3, 0/6, 0/5, 0/6).

**Open risks:**
- **`docker compose` недоступен в сессии** (новый риск P5.1): `docker compose ps`/`exec` падают на
  интерполяции `MINIO_ROOT_PASSWORD` (переменная не резолвится в шелле сессии) — использован
  `docker exec agelto_app …` на уже поднятый контейнер той же compose-стопки (семантика идентична,
  Known Deviations P5.1). Следующей сессии может понадобиться тот же обход, если .env/шелл не
  почищен до неё.
- **Эфемерные логи тулинга в bookkeeping-коммите**: буквальный `git add plans/<PLAN-ID>` затягивает
  `.claude/`/`.swissknife/` внутри каталога плана — перед bookkeeping-коммитом смотреть `git status
  --short plans/<PLAN-ID>` и стажировать явными путями.
- Посторонний незакоммиченный дифф в рабочем дереве вне items P1–P5 (без изменений этим заходом):
  `.gitignore` (modified), `app/Models/Procurement/Ai/Fixture.php` (modified),
  `estimates/gemini-analys.md`, `.swissknife/` — назван ещё в Phase Handoff `phases/P4.md`.
- Остаточные WARN плана (не новые от этого захода): ложные срабатывания маркера
  неопределённости/оркестрации в DoR P1/P2 и одна запись Update Log длиннее 300 символов.
- `phpstan-baseline.neon` правится вне `Files` — кандидат в отдельный item `@property`-аннотаций
  (унаследовано, владелец не назван).
- Docs-sync follow-up (унаследован от P3): сведение `docs/03_Dev/document-pipeline.md` к конкретным
  стратегиям/представлениям — владелец не назван, остаётся открытым.
- Долг, названный явно `research/06-schema-decision.md` §7 и P5.1 Completion Notes: детерминированное
  доопределение `prc_estimate_totals.kind` по `label`/`@Caption` спекой обещано, кодом не реализовано
  ни в одной ветке (LLM или `.gsfx`) — план его не закрывает.

**Workarounds / Deferred / Open questions:** OQ-3 (боевой прогон `ai:eval:run` обеих задач гейтится
доступом к провайдеру) — P5.1–P5.5 исполняются без него; OQ-4 фазу не блокирует.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D116, Update Log §6) ·
`plans/2026.07.26-EST-TRAIN/phases/P5.md` (P5.1 Completion Notes — построчное соответствие
`Data.xml`→§8.2, P5.2 ТЗ целиком) · `plans/2026.07.26-EST-TRAIN/roadmap.md` (P5.2 — solo,
plan-run manual, implementation/high) · `app/Services/Procurement/Gold/GsfxGoldMapper.php` +
`GoldValidator.php` (докблоки — источник истины по решениям, не покрытым Implementation Rules
дословно) · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
