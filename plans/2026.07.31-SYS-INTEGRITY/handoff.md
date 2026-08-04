# HANDOFF — 2026-08-04 — after P6.6

**Next:** ЗАПУСК ВРУЧНУЮ: frontier/high

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — живая разведка внешнего источника (ЕИС): снятая фактура замораживает контракт фильтра, сиды и план боевого прогона (§3 Routing P4.1) |
| Context | continue (/clear) — ручной item |
| Суть | P4.1 — разведка ЕИС через прокси-скоуп площадки (D3): реестры, поля фильтров, форма листинга/детали, план боевого прогона |
| Команда | — |

Код-блок ниже несёт полную D18-форму:

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P4.1
```

**Сделано этим заходом (`plan-exec`, sonnet/medium, 2026-08-04).** P6.6 закрыт 🟢 Done,
item-коммит `d1b1aa2` (4 файла, все из `Files` item'а):

- **Runbook** `docs/03_Dev/runbooks/storage-provider-switch.md` — десять шагов процедуры смены
  S3-провайдера по `research/07-storage-addressing.md` §8 (шаг 0 «переименование логических имён
  дисков» → создать приёмник → материализовать `null` → включить фолбэк-цепочку → backfill с
  верификацией → **дельта-синк** (шаг 5, закрывает потерю горячих записей во время backfill'а) →
  флип конфига → окно наблюдения → снять фолбэк → уборка источника), у каждого шага — проверка и
  точка отката (у шага 9 отката нет — намеренно, поэтому отделён окном наблюдения от шага 8);
  чеклист семи ловушек (ETag, дрейф метаданных, троттлинг, права приёмника, версионирование,
  подписанные URL, кэши воркеров Horizon). Реальные CLI-опции `storage:disk-backfill` взяты из кода
  команды (`--materialize-only`, `--to=`, `--from-id`), не по памяти.
- **Зарегистрирован** в `docs/03_Dev/runbooks/_index.md`.
- **Раздел «Адресация файлов: колонка `disk`»** в `docs/03_Dev/database.md` — семантика `null`
  (диск по конфиг-ключу на момент чтения), инвариант материализации (D51), соглашение об именах
  дисков (D53), ссылка на runbook.
- **Раздел `Services/Storage/`** в `docs/03_Dev/architecture.md` — `FileLocationResolver`/
  `FileLocation`/`DiskBackfillCommand`, ссылки на `database.md` и runbook.
- `CLAUDE.md`/`.ai/guidelines/**` не тронуты (D64/D66 — фаза P6 их не меняет, переносить нечего).
- **Task Contract**: `pre_mutation: allow` · `pre_final: allow` (route `implementation/medium`,
  точное совпадение с §3 Routing P6.6: `Exec=plan-exec`).

Validation (на `d1b1aa2`): `pint --test` — 11 style issues, все вне диффа item'а (тот же состав,
что на P6.3–P6.5); `phpstan --memory-limit=1G` — `[OK] No errors`, новых записей baseline нет;
`migrate:fresh --seed` — зелёный; `php artisan test --no-tia` (D8, полный сюит) — 10 failed / 1
skipped / 1003 passed, тот же состав 10 падений, что на P6.3/P6.4/P6.5 (партиционный дрейф
`pg_partman`, `memory_limit`-конфликт `agelto_app`, arch-тест, permission denied — все вне Scope
P6.6), новых падений от диффа P6.6 нет; `grep -rn "storage-provider-switch"
docs/03_Dev/runbooks/_index.md` — вхождение есть.

**Status:** 🟢 Done. Known Deviations — нет.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — **фаза терминальна**: закрыты все шесть items (P6.1
  research, P6.2 миграции, P6.3 резолвер, P6.4 фолбэк, P6.5 backfill 🟠, P6.6 runbook/доки/приёмка).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. P6 закрыта — следующая фаза **P4**
  (ЕИС: разведка, типизированные фильтры, сиды кроулеров, боевой прогон), первый item — `P4.1`
  (research, ЖЁСТКОЕ предусловие боевого прогона P4.7 — терминальные P1/P5/P6, которые уже
  терминальны).
- **`## Phase Handoff` фаз P2 и P5 не собран** («—» в `phases/P2.md`/`phases/P5.md`); теперь и
  **P6** нуждается в формальном `plan-close` (текст в `phases/P6.md` подготовлен, но статус-борд
  и линт закрытия фазы штатно делает `/task:plan-close <ID> P6`, не `plan-exec`) — висящий
  bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Прочие follow-up без изменений**: `ExtractionOrchestrator` теряет семь старых полей паспорта
  (P5.5); `EstimateValidator::bcadd()` научная нотация (P5.4); `RerunRangeAction` не вызывает
  `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без писателя (B-4); `RerunRangeAction`
  work_type_id preserve-ветка без теста (P5.5); систематический timeout `EST-XLSX-KS2-001` (P5.7);
  партиционный дрейф `pg_partman` (`prc_events`), `memory_limit`-конфликт контейнера
  `agelto_app`, нерасследованный arch-тест `CoreKnowsNoDocumentTypeTest` (P5.8); четыре
  потребителя конфиг-ключа диска в обход резолвера (`FormatBenchRunner::producePdfAlt()`,
  `GoldBuildCommand`, `FixtureForm`, `FixturesVerifyCommand` — Pending Work P6.3).

**Sources of truth:**

- `research/07-storage-addressing.md` — вердикты, контракты, фолбэк, backfill и процедура cutover
  фазы P6 целиком; §8 — источник текста уже написанного runbook'а.
- `docs/03_Dev/runbooks/storage-provider-switch.md` — операционный текст процедуры (P6.6).
- `phases/P6.md` — Phase Context, ТЗ P6.1–P6.6, Completion Notes, Phase Handoff (фаза терминальна).
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items; P4.1–P4.8 — следующий блок.

**Open risks:**

- **`estimates_s3` объявлен и никем не адресуется** (`config/filesystems.php:74-85`) — мёртвое
  объявление, владелец не назначен.
- **Перевод корпуса эталонов на S3 сломает `GoldCorpusBuilder`** (`:162` вызывает `->path()`,
  которого у S3-драйвера нет) — независимо от адресации; решение вне плана.
- **10 упавших тестов приёмочного прогона** — партиционный дрейф `pg_partman`,
  `memory_limit`-конфликт `agelto_app`, один arch-тест, permission denied на `resources/`.
  Владелец не назначен; тот же состав из 10 на P6.3/P6.4/P6.5/P6.6, новых падений нет.
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7) —
  датасет для следующего item'а, которому он понадобится, восстанавливать
  `estimate:gold:import --prepare`.
- **`app/Filament/Resources/Procurement/** ` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife*`, `.swissknifeman/`,
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений;
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …`, подтверждено действующим на P6.3–P6.6.
- **PHPStan в контейнере `agelto_app` падает на дефолтном 256M лимите** — обход
  `--memory-limit=1G`, подтверждено на P6.3–P6.6.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app` вместо `docker compose exec` (`MINIO_ROOT_PASSWORD`);
  `phpstan analyse --memory-limit=1G` вместо дефолта — без изменений, см. Open risks.
- **Deferred:** `## Phase Handoff` P2/P5 (нужен `plan-close`); формальный `plan-close` P6
  (bookkeeping/статус-борд/линт закрытия фазы); писатель `verified_at`/`verified_by`; писатель пяти
  колонок `source_*`; прокидка семи старых полей паспорта в `ExtractionOrchestrator`;
  материализация не-`Item` строк; тип нормы аксессором из `code`; типизированный носитель `RowType`
  (OQ-6); переписывание текстов промтов сверх дедупликации (OQ-7); партиционирование
  `prc_events`/`llm_calls`; `RerunRangeAction` `replaceResources()`; научная нотация в
  `EstimateValidator::bcadd()`; тест на preserve-ветку `work_type_id`; персистентный подъём
  `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф `pg_partman` под тестовые даты,
  `memory_limit`-конфликт `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`; судьба мёртвого диска
  `estimates_s3`; колонка диска для `prc_ai_dataset_examples.gold_ref` (заводится вместе с
  писателем, если он появится); перевод `FormatBenchRunner`/`GoldBuildCommand`/`FixtureForm`/
  `FixturesVerifyCommand` на `FileLocationResolver` (Pending Work P6.3); реальное удаление объектов
  на исходном диске после окна отката (Scope Excluded P6.5/P6.6 — операционное решение владельца).
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113.
