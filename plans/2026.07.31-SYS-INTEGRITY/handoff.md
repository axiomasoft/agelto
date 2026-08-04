# HANDOFF — 2026-08-04 — after P5.4

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — миграции + enum'ы + Data-контракты + персистер + спека §8.2 одним item'ом (§3 Routing P5.5, класс «migrations» всегда `full`) |
| Context | continue (/clear) — ручной item |
| Суть | P5.5 — по решению P5.3 (research/06-estimate-completeness.md): одна миграция `add_regulatory_base_to_prc_estimates_table`, три поля контракта паспорта, два писателя класса B (unit-нормализатор, `prc_estimate_work_types`), ноль enum'ов, ноль индексов |
| Команда | — |

Код-блок ниже несёт полную D18-форму:

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P5.5
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-04).** P5.4 закрыт 🟢 Done, item-коммит
`f21ecb7`; Deliverable — `findings/P5-eval-baseline.md`:

- **Апрув владельца по цене получен ДО прогона** (чат, дословно): «Да, апрувить до ~$1.2» — оценка
  строилась на факте семи сопоставимых `--split=all` прогонов `EvalRun #13–#19`
  ($0.5635–$1.1529, медиана $0.6943); ни один из них НЕ годился как сам baseline — все сняты ДО
  фикса writer-путей P5.2 (`576794b`, 2026-08-04 17:04).
- **Боевой baseline — `EvalRun #22`** (2026-08-04 14:47–14:54, `gemini-3.5-flash`, 44 вызова, факт
  **$0.7322**, в пределах апрува): 5/6 эталонов, общий `precision=0.2896 recall=0.2051 f1=0.1064
  row_coverage=0.7081`. Полные таблицы (по эталонам, `per_field`, `passport`, `totals`, состав
  сплитов) — `findings/P5-eval-baseline.md` §4.
- **Критерий приёмки P5.7 записан числами**: P2.2 закрыт 🟢, значит режим `--criterion=
  no-regression` (`EvalRunComparator`) — holdout F1 max drop 0.005, row_coverage max drop 0.005,
  per-fixture F1 max drop 0.05 (`EvalRunComparator.php:44-48`, величины НЕ менялись). Команда P5.7:
  `estimate:eval:compare --base=22 --candidate=<run_id P5.7> --criterion=no-regression`.
- **Находка вне Scope P5.4 (не Known Deviations — не вызвана работой item'а):**
  `EstimateValidator::bcadd()` (`EstimateValidator.php:147,192`) падает необработанным `ValueError`
  на `total_price_curr` в научной нотации от LLM-вывода (упал `EST-XLSX-VOR-001`, dataset_example
  #16) — тот же класс дефекта уже решён в `GsfxGoldMapper::cleanNumeric()`/
  `expandScientificNotation()`, но нормализация не переиспользована в `EstimateValidator`.
  Фикс-предложение — `findings/P5-eval-baseline.md` §5: кандидат отдельного follow-up item'а,
  владелец не назначен, приоритет решает владелец. P5.7 несёт тот же риск повторно (LLM
  недетерминирован) — учесть при трактовке вердикта `estimate:eval:compare`.
- Код/конфиг/промты этот item НЕ трогал (Scope Excluded) — `git status` рабочего дерева кода чист.

**Status:** 🟢 Done. Known Deviations — нет; Pending Work — follow-up item (нормализация научной
нотации в `EstimateValidator::bcadd()`, владелец не назначен).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5.1** 🟠, **P5.2** 🟠, **P5.3** 🟢, **P5.4** 🟢 — фаза P5 **не терминальна** (4 items впереди).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Внутри P5 порядок items совпадает с
  нумерацией: следующий — **P5.5** (миграции/enum'ы/Data-контракты/персистер/спека §8.2,
  `implementation/high manual`, класс «migrations» — всегда `full`, `§9 D6`).
- **P5.5 — предусловие для P5.7:** baseline `EvalRun #22` уже снят и годен — P5.5 может стартовать
  без дополнительного гейта владельца по деньгам (тот гейт был именно у P5.4).
- **`## Phase Handoff` фазы P2 не собран** («—») — висящий bookkeeping-хвост:
  `/task:plan-close 2026.07.31-SYS-INTEGRITY P2`, продолжение P5 не блокирует.
- **Follow-up вне плана:** десять pre-existing красных тестов (`open-questions.md` OQ-8 п.3) —
  этим item'ом не затронуты (кода нет). **Новый follow-up:** `EstimateValidator::bcadd()` не
  нормализует научную нотацию до арифметики (P5.4, см. выше) — владелец не назначен.
- **Pending Work P5.2:** `RerunRangeAction` не вызывает `replaceResources()` вовсе — находка P5.1,
  вне Scope P5.2; владелец не назначен, фикс — НОВЫЙ item при заведении.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/06-estimate-completeness.md` — утверждённое
  распределение: таблицы A/B/C, индексы, отложенные, вердикты D46, точный вход P5.5 и P5.6.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-eval-baseline.md` — числа baseline, `run_id=22`,
  критерий приёмки P5.7 (вход P5.7).
- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-corpus-coverage.md` — инвентарь записи и
  дельта-сверка (вход P5.3).
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/persistence_inventory.php` — воспроизводимый зонд
  записи (реюз P5.2; понадобится P5.5 для контрольной сверки новых полей).
- `phases/P5.md` — Phase Status, Completion Notes P5.1–P5.4.
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items.

**Open risks:**

- **Три новых поля паспорта не измеряются eval'ом** (§10 `research/06-estimate-completeness.md`):
  `GsfxReader` не читает `DocDates`/`Overhd_Info` (долг донора §7, вне Scope P5), `SheetGoldMapper`
  паспорт не строит. Качество их извлечения будет видно только на боевых прогонах, не на F1.
- **`EstimateValidator::bcadd()` не нормализует научную нотацию** (найдено P5.4) — падает на любом
  документе, где LLM выдаст `total_price_curr`/аналог в форме `NeM`; P5.7 может повторно поймать
  тот же класс сбоя на ЛЮБОМ эталоне, не обязательно на `EST-XLSX-VOR-001`.
- **Пять колонок `prc_estimates.source_*` остаются без писателя** — донор назначил его item'ам,
  которые закрылись без реализации; в этом плане владельца нет (P6 — про диски файлов). Кандидат
  отдельного fix-item; после боевого прогона P4.7 колонки останутся пустыми.
- **Implementation Rules п.4 P5.1 неполно выполнено** на 4+3 колонках/ключах (Known Deviations
  P5.1) — без изменений.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife*`, `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений; состав снимать
  `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …`; для tinker-прогонов на `agelto_test` нужны явные
  `-e DB_DATABASE=agelto_test -e DB_USERNAME=agelto -e DB_PASSWORD=agelto_dev_local`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** те же, что в предыдущем handoff (см. Open risks).
- **Deferred:** `## Phase Handoff` P2 (нужен `plan-close`); писатель `verified_at`/`verified_by`
  (долг вне плана, D89 донора); писатель пяти колонок `source_*` (кандидат отдельного fix-item);
  материализация не-`Item` строк (решение владельца «не материализовать», признак дрейфа — строка
  C-11 карты следствий); тип нормы аксессором из `code` (потребитель не назван); типизированный
  носитель `RowType` (OQ-6); переписывание текстов промтов сверх дедупликации (OQ-7);
  партиционирование `prc_events`/`llm_calls`; `RerunRangeAction` `replaceResources()`;
  нормализация научной нотации в `EstimateValidator::bcadd()` (P5.4).
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113. Гейт P5.3 по двум кандидатам (GAP-2, GAP-8) — закрыт апрувом владельца 2026-08-04.
  Гейт P5.4 по цене цикла — закрыт апрувом владельца 2026-08-04 (до ~$1.2, факт $0.7322).
