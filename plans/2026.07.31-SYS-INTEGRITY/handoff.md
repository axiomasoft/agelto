# HANDOFF — 2026-08-04 — after P5.6

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — правка боевых промтов + платный контрольный прогон + вердикт непрегрессии по критерию P5.4 (§3 Routing P5.7) |
| Context | continue (/clear) — ручной item |
| Суть | P5.7 — дописать в промты `extraction-rows`/`extraction-passport`/`extraction-totals` (и `-global`, если правило сквозное) поведенческие правила ТОЛЬКО по трём новым полям паспорта (`regulatory_base`/`regulatory_amendments`/`index_letter`), `ai:prompts:sync`, снять контрольный прогон `estimate:eval:run --split=all` на пересобранном gold (P5.6 закрыт) и вынести вердикт непрегрессии по критерию `findings/P5-eval-baseline.md` (P5.4) |
| Команда | — |

Код-блок ниже несёт полную D18-форму:

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P5.7
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-04).** P5.6 закрыт 🟢 Done,
item-коммит `57dd7a1` (8 файлов — 6 `*.gold.json` + 2 теста, все из `Files` item'а):

- **Мапперы — 0 правок.** `research/06-estimate-completeness.md` §10/§11 (Inputs item'а) уже
  зафиксировали вердикт D46 ДО старта работы: три новых поля паспорта (`regulatory_base`/
  `regulatory_amendments`/`index_letter`, добавленные P5.5) машинно не заполняются ни одним
  gold-маппером — `GsfxGoldMapper::mapPassport()`/`SheetGoldMapper` собирают `PassportResponse`/
  `EstimatePassport` именованными аргументами, новые поля берут контрактный дефолт `null` без
  правки вызывающего кода (подтверждено чтением обоих файлов). Scope Included «расширение
  мапперов … полями, утверждёнными как машинно-заполняемые» пуст по построению, не пропущен.
- **Пересборка**: `estimate:gold:build --dry-run` исполним, `estimate:gold:build --update-manifest`
  пересобрал все шесть `*.gold.json` (диф — ровно три новых `null`-ключа на файл); `manifest.json`
  не изменился. Байт-стабильность подтверждена дважды: `sha256sum` между двумя прогонами ДО
  коммита и `git diff --stat estimates/gold` пуст на прогоне ПОСЛЕ item-коммита (`57dd7a1`).
- **`GoldValidator`** пуст на всех шести пересобранных эталонах (прямой прогон + новый тест).
  **`estimate:fixtures:verify --json`** — `24/24`, `0` расхождений.
- **Измеримость `work_type_id` (P5.5) — число, которое обязан назвать P5.6** (`research/06` §11):
  зонд `persistence_inventory.php` на пересобранном gold — `gold_nonempty=110, db_nonempty=110`
  на 214 строках корпуса (было `db_nonempty=0` до P5.5) — единственная величина фазы, у которой
  измеримость выросла этим тактом.
- **`EvalMetrics::*_FIELD_MAP`** — без изменений (0 новых ключей), как предписано `research/06`
  §11; новый guard-тест `tests/Feature/Ai/EvalMetricsTest.php` фиксирует границу D46 — три новых
  поля паспорта НЕ входят в `PASSPORT_FIELD_MAP`.
- **Тесты**: `--filter=GoldValidator` — 11 passed (1 новый); `--filter=EvalMetrics` — 21 passed
  (1 новый); полный сюит — 983 passed / 10 failed / 1 skipped на `57dd7a1` (те же 10 pre-existing
  красных, что в предыдущих handoff'ах; выросло ровно на 2 новых теста). Pint (scoped) чист,
  PHPStan `[OK]`, baseline 61 без изменений. `migrate:fresh --seed` зелёный (миграции P5.5
  применяются чисто); побочный эффект прогона — `migrate:fresh` очистил `prc_ai_fixtures` на
  dev-БД, восстановлено `estimate:fixtures:import` (24/24) до продолжения Validation.
- **Task Contract**: `pre_mutation: allow` · `pre_final: allow` (route `implementation/high`,
  точное совпадение с Routing — не перебор), гейт прогнан строго ДО первой мутации (без
  process-долга P5.5).

**Status:** 🟢 Done. Known Deviations — нет (диф item-коммита — ровно `Files` item'а).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5.1** 🟠, **P5.2** 🟠, **P5.3** 🟢, **P5.4** 🟢, **P5.5** 🟠, **P5.6** 🟢 — фаза P5 **не
  терминальна** (2 items впереди: P5.7/P5.8).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Внутри P5 порядок items совпадает с
  нумерацией: следующий — **P5.7** (промты + контрольный прогон + вердикт непрегрессии,
  `implementation/high manual`, платный внешний эффект).
- **P5.7 предусловие**: пересобранный gold (P5.6 закрыт), `findings/P5-eval-baseline.md` (P5.4,
  критерий числами).
- **`## Phase Handoff` фазы P2 не собран** («—») — висящий bookkeeping-хвост:
  `/task:plan-close 2026.07.31-SYS-INTEGRITY P2`, продолжение P5 не блокирует.
- **Прочие follow-up без изменений**: `ExtractionOrchestrator` теряет семь старых полей паспорта
  (P5.5, владелец не назначен); `EstimateValidator::bcadd()` научная нотация (P5.4);
  `RerunRangeAction` не вызывает `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без
  писателя (B-4); `RerunRangeAction` work_type_id preserve-ветка без отдельного теста (P5.5).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/06-estimate-completeness.md` — утверждённое
  распределение: таблицы A/B/C, индексы, отложенные, вердикты D46, точный вход P5.5 и P5.6.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-eval-baseline.md` — числа baseline, `run_id=22`,
  критерий приёмки P5.7 (вход P5.7).
- `phases/P5.md` — Phase Status, Completion Notes P5.1–P5.6.
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items.

**Open risks:**

- **`ExtractionOrchestrator` теряет семь старых полей паспорта** (находка P5.5) — в бою
  `calc_method`/`labor_hours_*`/`document_*`/`period_*` всегда `null`; кандидат отдельного
  fix-item, владелец не назначен.
- **Три новых поля паспорта (P5.5) не измеряются eval'ом** (D46, P5.6 подтвердил вердикт числом):
  `GsfxReader` не читает `DocDates`/`Overhd_Info` (долг донора §7, вне Scope P5), `SheetGoldMapper`
  паспорт не строит. P5.7 не расширяет их измеримость промтами — правило поведения без gold-эталона
  всё равно не даст числа.
- **`EstimateValidator::bcadd()` не нормализует научную нотацию** (найдено P5.4) — падает на любом
  документе, где LLM выдаст `total_price_curr`/аналог в форме `NeM`; P5.7 может повторно поймать
  тот же класс сбоя на контрольном прогоне.
- **Пять колонок `prc_estimates.source_*` остаются без писателя** — донор назначил его item'ам,
  которые закрылись без реализации; в этом плане владельца нет (P6 — про диски файлов).
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife*`, `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений; состав снимать
  `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …`; для tinker-прогонов на `agelto_test` нужны явные
  `-e DB_DATABASE=agelto_test -e DB_USERNAME=agelto -e DB_PASSWORD=agelto_dev_local`.
- **`migrate:fresh` на dev-БД очищает `prc_ai_fixtures`** — любой item, гоняющий и
  `migrate:fresh --seed`, и `estimate:gold:build`/`estimate:fixtures:*` в одном заходе, обязан
  восстановить реестр `estimate:fixtures:import` между ними (найдено этим item'ом, не блокер —
  workaround известен).

**Workarounds/Deferred/Open questions:**

- **Workarounds:** те же, что в предыдущем handoff'е (см. Open risks) + новый —
  `estimate:fixtures:import` после `migrate:fresh` на dev-БД.
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
