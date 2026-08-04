# HANDOFF — 2026-08-04 — after P5.5

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — gold-мапперы + расширение EvalMetrics + байт-стабильность пересборки эталонов (§3 Routing P5.6) |
| Context | continue (/clear) — ручной item |
| Суть | P5.6 — сделать `regulatory_base`/`work_type_id` измеримыми: `GsfxGoldMapper` полями, утверждёнными P5.3 как машинно-заполняемые (только `work_type_name`, паспортные поля GAP-8 — вердикт D46 «не заполняются»), пересборка шести эталонов, дописывание в `EvalMetrics::FIELD_MAP`/`PASSPORT_FIELD_MAP` |
| Команда | — |

Код-блок ниже несёт полную D18-форму:

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P5.6
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-04).** P5.5 закрыт 🟠 Done with
deviations, item-коммит `146d6ab`:

- **Миграция** — одна, `add_regulatory_base_to_prc_estimates_table` (`text nullable`); применённые
  `create_*` не тронуты. Новых колонок в диффе — 1, ровно число строк таблицы A `research/
  06-estimate-completeness.md` §2.
- **Контракты**: `PassportResponse::$regulatory_base` (новое) · `EstimatePassport::
  $regulatory_amendments`/`$index_letter` (новые) + докблок `$reference` сужен до основания
  составления сметы (§6 `research/06`). `RowAttributes::ALLOWED_KEYS` — без изменений. Снапшот
  `tests/Fixtures/Llm/step-json-schemas.json` пересобран.
- **Писатели класса B (B-1/B-2/B-5)**: новый `UnitNormalizer::normalize()` — тотальная функция
  `unit` → `unit_base`/`unit_multiplier`, единственный носитель, вызывается из
  `EstimatePersister::itemAttributes()` (позиция) и из маппинга ресурсов (ресурс) — оба
  writer-пути (`EstimatePersister`/`RerunRangeAction`) читают один код. `EstimateStoreRepository::
  findOrCreateWorkType()` резолвит `work_type_name` → `work_type_id` по `(estimate_id, title)` в
  обоих writer-путях (точечный re-run — preserve при пустом ответе, та же логика, что
  `preserveUnrecomputed()`); `replaceWorkTypes()` пишет дерево `PassportResponse::$work_types`.
- **Спека** `docs/specs/v2/08-estimate-extraction.md` §3.1/§8.2 обновлена.
- **Тесты**: 10 новых (2 контракты, 4 персистер, 1 схема, плюс существующие продолжают проходить) —
  полный сюит 981 passed / 10 failed (те же pre-existing, что в предыдущих handoff'ах) / 1 skipped
  на `146d6ab`. Pint (scoped) чист, PHPStan `[OK]`, baseline 61 без изменений.
- **Task Contract**: `pre_mutation: allow` · `pre_final: allow` (route `implementation/high`, точное
  совпадение с Routing — не перебор). Известный process-долг — контракт прогнан после старта правок,
  а не строго до (см. Known Deviations phases/P5.md P5.5).
- **Находка вне Scope P5.5, НЕ починена (Pending Work)**: `ExtractionOrchestrator` не прокидывает
  семь СТАРЫХ полей паспорта (`calc_method`/`labor_hours_workers`/`labor_hours_machinists`/
  `document_number`/`document_date`/`period_start`/`period_end`, добавленных P5.2 в
  `upsertEstimate()`) из `PassportResponse` в `analysis.result.estimates[]` ни в одном из двух
  путей извлечения — в бою эти семь колонок ВСЕГДА `null`, хотя P5.2 подтвердил писатель на данных,
  собранных напрямую из gold (тест кормит персистер напрямую, минуя оркестратор). Найдено при
  проводке `regulatory_base` тем же путём (этот item прокинул ТОЛЬКО восьмое, новое поле паспорта).
  Владелец не назначен — кандидат отдельного fix-item, приоритет решает владелец.

**Status:** 🟠 Done with deviations. Known Deviations — 4 файла вне `Files` item'а
(`EstimatePassport.php`, `ExtractionOrchestrator.php`, новый `UnitNormalizer.php`,
`JsonSchemaGeneratorTest.php` — все необходимы для выполнения собственных Deliverables item'а,
детали phases/P5.md P5.5 Known Deviations) + process-долг по порядку Task Contract.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5.1** 🟠, **P5.2** 🟠, **P5.3** 🟢, **P5.4** 🟢, **P5.5** 🟠 — фаза P5 **не терминальна**
  (3 items впереди: P5.6/P5.7/P5.8).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Внутри P5 порядок items совпадает с
  нумерацией: следующий — **P5.6** (gold-мапперы + `EvalMetrics`, `implementation/high manual`).
- **P5.6 предусловие**: Completion Notes P5.5 (фактические имена полей — уже зафиксированы выше и
  в `phases/P5.md`).
- **`## Phase Handoff` фазы P2 не собран** («—») — висящий bookkeeping-хвост:
  `/task:plan-close 2026.07.31-SYS-INTEGRITY P2`, продолжение P5 не блокирует.
- **Новый follow-up вне Scope этого плана-item'а**: `ExtractionOrchestrator` теряет семь старых
  полей паспорта на пути в БД (см. выше, Pending Work P5.5) — владелец не назначен.
- **Прочие follow-up без изменений**: `EstimateValidator::bcadd()` научная нотация (P5.4);
  `RerunRangeAction` не вызывает `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без
  писателя (B-4); `RerunRangeAction` work_type_id preserve-ветка без отдельного теста (P5.5).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/06-estimate-completeness.md` — утверждённое
  распределение: таблицы A/B/C, индексы, отложенные, вердикты D46, точный вход P5.5 и P5.6.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-eval-baseline.md` — числа baseline, `run_id=22`,
  критерий приёмки P5.7 (вход P5.7).
- `phases/P5.md` — Phase Status, Completion Notes P5.1–P5.5.
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items.

**Open risks:**

- **`ExtractionOrchestrator` теряет семь старых полей паспорта** (новая находка P5.5, см. Pending
  Work) — в бою `calc_method`/`labor_hours_*`/`document_*`/`period_*` всегда `null`; кандидат
  отдельного fix-item, владелец не назначен.
- **Три новых поля паспорта (P5.5) не измеряются eval'ом** (§10 `research/06-estimate-
  completeness.md`, вердикт D46): `GsfxReader` не читает `DocDates`/`Overhd_Info` (долг донора §7,
  вне Scope P5), `SheetGoldMapper` паспорт не строит. P5.6 фиксирует вердикт, кода не пишет для
  этих трёх полей.
- **`EstimateValidator::bcadd()` не нормализует научную нотацию** (найдено P5.4) — падает на любом
  документе, где LLM выдаст `total_price_curr`/аналог в форме `NeM`; P5.7 может повторно поймать
  тот же класс сбоя.
- **Пять колонок `prc_estimates.source_*` остаются без писателя** — донор назначил его item'ам,
  которые закрылись без реализации; в этом плане владельца нет (P6 — про диски файлов).
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
  прокидка семи старых полей паспорта в `ExtractionOrchestrator` (найдено P5.5); материализация
  не-`Item` строк (решение владельца «не материализовать»); тип нормы аксессором из `code`
  (потребитель не назван); типизированный носитель `RowType` (OQ-6); переписывание текстов промтов
  сверх дедупликации (OQ-7); партиционирование `prc_events`/`llm_calls`; `RerunRangeAction`
  `replaceResources()`; нормализация научной нотации в `EstimateValidator::bcadd()` (P5.4);
  тест на preserve-ветку `work_type_id` в `RerunRangeAction` (P5.5).
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113. Гейт P5.3 по двум кандидатам (GAP-2, GAP-8) — закрыт апрувом владельца 2026-08-04.
  Гейт P5.4 по цене цикла — закрыт апрувом владельца 2026-08-04 (до ~$1.2, факт $0.7322).
