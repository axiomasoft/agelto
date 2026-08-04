# HANDOFF — 2026-08-04 — after P5.8

**Next:** ЗАПУСК ВРУЧНУЮ: frontier/high

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — design-решения: вердикт по каждому носителю, контракт резолвера, процедура cutover (§3 Routing P6.1) |
| Context | continue (/clear) — ручной item |
| Суть | P6.1 — инвентарь носителей файловых ссылок, вердикты по критерию D50, проект резолвера адреса и процедуры смены S3-провайдера |
| Команда | — |

Код-блок ниже несёт полную D18-форму:

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P6.1
```

**Сделано этим заходом (`plan-run`, sonnet/low, 2026-08-04).** P5.8 закрыт 🟠 Done with
deviations, item-коммит `8902b5c` (3 файла — `docs/03_Dev/database.md`,
`.ai/guidelines/glossary.md`, `.claude/skills/estimate-extraction-dev/SKILL.md`, все из `Files`
item'а):

- **Канон сметной модели зафиксирован в доках**: `docs/03_Dev/database.md` несёт колонку
  `prc_estimates.regulatory_base`, детерминированный `unit_base`/`unit_multiplier` на
  `prc_estimate_items`/`prc_estimate_resources`, резолв `work_type_id` без нормативов НР/СП на
  боевом LLM-пути, решение «не материализовать» для строк `row_type != item`, новый раздел
  «Критерий подтверждения величины сметной схемы» (порог `N ≥ 9` + тесты). Ссылка в глоссарии
  (`.ai/guidelines/glossary.md`) ведёт на этот раздел, а не на `plans/…` (план уйдёт в архив).
- **`docs/specs/v2/08-estimate-extraction.md` §3.1/§8.2, `docs/02_Workflow/glossary.md`,
  `docs/02_Workflow/document-and-estimate-pipeline.md`, `CLAUDE.md`** — сверены, правок НЕ
  потребовалось: §3.1/§8.2 уже несут три новых поля паспорта текстом P5.5; `document-and-
  estimate-pipeline.md` таблиц/колонок не описывает; `CLAUDE.md` — куррированная выжимка глоссария
  (не 1:1 зеркало), новый термин туда намеренно не перенесён.
- **`.claude/skills/estimate-extraction-dev/SKILL.md`** (без маркера `swissknifeman:managed`) —
  уточнены границы `EstimatePersister` (не-`Item` строки, нормализатор `unit`, резолв
  `work_type_id`).
- **Приёмочный прогон фазы** (`docker exec agelto_app`, `.env` не несёт `MINIO_ROOT_PASSWORD`
  для `docker compose exec`): `php artisan test` — **10 failed / 1 skipped / 983 passed**;
  `phpstan analyse` — **No errors** (543/543); baseline — **61** (неизменно); `pint --dirty --test`
  — 1 style issue в постороннем untracked `config/cms.php` (вне `Files` item'а, не патчится).
  Все 10 упавших тестов — вне Scope P5.8 (докс-only item, диффа кода нет вообще) и не регрессия
  этого item'а: партиционный дрейф `pg_partman` (`prc_events`, дата теста вне окна партиции),
  `memory_limit`-конфликт контейнера `agelto_app`, один arch-тест (`CoreKnowsNoDocumentTypeTest`).
- **Task Contract**: `pre_mutation: allow` · `pre_final: allow` (route `implementation/low`, точное
  совпадение с Routing P5.8 — не перебор).

Полные числа и разбивка находок — `phases/P5.md` P5.8 Completion Notes/Pending Work.

**Status:** 🟠 Done with deviations. Known Deviations — одна (Validation-клауза «полный сюит
зелёный» выполнена не буквально: 10 упавших тестов вне Scope, не регрессия диффа P5.8).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — **фаза терминальна** (все 8 items закрыты: P5.3/P5.4/P5.6
  🟢, P5.1/P5.2/P5.5/P5.7/P5.8 🟠).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Фаза P5 терминальна — следующая
  фаза по очереди: **P6** (адресация файлов), первый item — **P6.1**
  (`frontier/high manual` — design-решения, `plan-exec`/`plan-run` пином не покрывается).
- **`## Phase Handoff` фазы P5 не собран** («—» в `phases/P5.md`) — висящий bookkeeping-хвост
  фазы, штатно закрывается `/task:plan-close 2026.07.31-SYS-INTEGRITY P5` перед/вместе со стартом
  P6; продолжение плана НЕ блокирует (тот же прецедент — P2, до сих пор не закрыт).
- **Прочие follow-up без изменений**: `ExtractionOrchestrator` теряет семь старых полей паспорта
  (P5.5, владелец не назначен); `EstimateValidator::bcadd()` научная нотация (P5.4);
  `RerunRangeAction` не вызывает `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без
  писателя (B-4); `RerunRangeAction` work_type_id preserve-ветка без отдельного теста (P5.5);
  систематический timeout `EST-XLSX-KS2-001` на 150-секундном лимите (P5.7); **новое (P5.8)**:
  партиционный дрейф `pg_partman` (`prc_events`) под текущую дату тестов, `memory_limit`-конфликт
  контейнера `agelto_app`, один нерасследованный arch-тест (`CoreKnowsNoDocumentTypeTest`).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/06-estimate-completeness.md` — утверждённое
  распределение P5 целиком.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-eval-baseline.md` / `findings/P5-eval-run.md` —
  числа baseline и контрольного прогона P5.7.
- `phases/P5.md` — Phase Status, Completion Notes P5.1–P5.8 (фаза терминальна).
- `phases/P6.md` — Phase Context + P6.1 целиком (следующий item).
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items.

**Open risks:**

- **10 упавших тестов приёмочного прогона P5.8** (новое) — партиционный дрейф `pg_partman`
  (`prc_events`, окно ретеншена не покрывает дату теста), `memory_limit`-конфликт контейнера
  `agelto_app`, один нерасследованный arch-тест. Кандидаты отдельных `chore`/fix-item'ов вне P5
  (инфраструктура/CI, не сметная модель), владелец не назначен.
- **Систематический (дважды подряд) timeout `EST-XLSX-KS2-001`** на 150-секундном лимите
  `AI_GEMINI_REQUEST_TIMEOUT_SECONDS` (найдено P5.7) — владелец не назначен.
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (найдено
  P5.7) — любой item, гоняющий `migrate:fresh --seed` и затем eval-команды, обязан восстановить
  датасет `estimate:gold:import --prepare` между ними.
- **`ExtractionOrchestrator` теряет семь старых полей паспорта** (находка P5.5) — владелец не
  назначен.
- **`EstimateValidator::bcadd()` не нормализует научную нотацию** (найдено P5.4).
- **Пять колонок `prc_estimates.source_*` остаются без писателя** — владельца в этом плане нет.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife*`, `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений; состав снимать
  `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …` (действует и для P6).

**Workarounds/Deferred/Open questions:**

- **Workarounds:** те же, что в предыдущем handoff'е (см. Open risks) — без изменений.
- **Deferred:** `## Phase Handoff` P2/P5 (нужен `plan-close`); писатель `verified_at`/`verified_by`
  (долг вне плана, D89 донора); писатель пяти колонок `source_*`; прокидка семи старых полей
  паспорта в `ExtractionOrchestrator`; материализация не-`Item` строк (решение владельца «не
  материализовать»); тип нормы аксессором из `code`; типизированный носитель `RowType` (OQ-6);
  переписывание текстов промтов сверх дедупликации (OQ-7); партиционирование
  `prc_events`/`llm_calls`; `RerunRangeAction` `replaceResources()`; нормализация научной нотации
  в `EstimateValidator::bcadd()`; тест на preserve-ветку `work_type_id` в `RerunRangeAction`;
  персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; **новое** — партиционный дрейф
  `pg_partman` под тестовые даты, `memory_limit`-конфликт `agelto_app`, разбор
  `CoreKnowsNoDocumentTypeTest`.
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113.
