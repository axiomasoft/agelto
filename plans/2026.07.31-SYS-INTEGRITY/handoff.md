# HANDOFF — 2026-08-04 — after P6.5

**Next:** `/task:plan-exec 2026.07.31-SYS-INTEGRITY P6.6`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — синхронизация доков/раннбука по уже принятым решениям фазы и приёмочный прогон,
новых решений нет (§3 Routing P6.6: `implementation/low`, `Exec=plan-exec`, `Review=light`) |
| Context | continue (/clear) — ручной item |
| Суть | P6.6 — runbook смены S3-провайдера (источник — `research/07-storage-addressing.md` §8),
раздел в `docs/03_Dev/`, приёмочный прогон полного сюита (фаза P6 терминальна после закрытия) |

Код-блок ниже — копипаст без правок (`plan-exec` пинит sonnet/medium сам, `/model`+`/effort` не
нужны):

```
/task:plan-exec 2026.07.31-SYS-INTEGRITY P6.6
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-04).** P6.5 закрыт 🟠 Done with
deviations, item-коммит `32fda0d` (7 файлов):

- **`storage:disk-backfill`** (`DiskBackfillCommand`/`DiskBackfillRunner`, новые) — два режима:
  `--materialize-only` проставляет `null` → фактическое имя диска ДО флипа конфига (D51), не читая
  ни одного объекта; `--to=<disk>` переносит объекты потоком (`readStream`/`writeStream`) с
  верификацией sha256/размера, запись обновляется ТОЛЬКО после совпадения на приёмнике; расхождение
  не валит прогон (строка в отчёт, код возврата ненулевой); `--from-id` — резюмируемый курсор.
  Три носителя: `document-versions`, `fixtures`, `estimate-raw-results`.
- **Общий алгоритм работает с примитивной формой строки** (`id`/`disk`/`path`/`hash`) — три
  типизированных приватных генератора (`documentRows`/`fixtureRows`/`estimateRows`) конвертируют
  модель → примитив на границе, без union-типа моделей и без generic `resolve(mixed)` (тот же приём
  полиморфизма через отдельные методы, что `FileLocationResolver`, P6.3) — иначе PHPStan не мог
  статически подтвердить доступ к разным свойствам (`disk` vs `raw_result_disk`, `storage_path` vs
  `raw_result_ref`) на union-типе модели.
- **Эталон хэша**: `document-versions`/`fixtures` — читается из колонки без I/O
  (`content_hash`/`sha256`); `estimate-raw-results` (колонки нет, D114) — считается потоком с
  источника ДО копирования; если строка уже на целевом диске и сохранённого эталона нет, backfill
  доверяет предыдущей верификации записи (диск обновляется писателем только ПОСЛЕ совпадения) и
  пропускает без чтения.
- **6 тестов** (`tests/Feature/Storage/DiskBackfillTest.php`, 22 assertions): перенос+обновление
  диска, идемпотентность повторного прогона, возобновление с `--from-id`, расхождение хэша на
  приёмнике (диск НЕ обновлён, код 1), `--materialize-only` не пишет объектов, `estimate-raw-results`
  считает эталон с источника.
- **Task Contract**: `pre_mutation: allow` · `pre_final: allow` (route `implementation/high`, точное
  совпадение с §3 Routing P6.5 — тот же паттерн, что P6.2–P6.4: Routing предписывал `Exec=manual`,
  item исполнен `plan-run` инвертированным гейтом под фактическим маршрутом сессии, D75; не перебор).

Полные числа и разбивка Validation — `phases/P6.md` P6.5 Completion Notes.

**Status:** 🟠 Done with deviations. Known Deviations — material: item-коммит несёт дифф вне
`Files` P6.5 (три StoreRepository — новые read/write-методы, продиктованные Implementation Rules
«персистентность только через репозиторий»; `Estimate.php` — одна строка докблока
`@property $raw_result_ref`, снимает PHPStan-находку).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 4/6** 🟡 In progress — закрыты P6.1 (research), P6.2 (миграции колонки диска), P6.3
  (резолвер адреса), P6.4 (фолбэк-цепочка), P6.5 (backfill); остаётся P6.6.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P6, последний item —
  `P6.6` (номер = очередь, зависимости линейны: потребляет Completion Notes P6.1–P6.5).
- **P6.6 (runbook + доки + приёмка)** — `research/07-storage-addressing.md` §8 (источник текста
  runbook'а смены S3-провайдера) + раздел в `docs/03_Dev/`; синхронизация доков по уже принятым
  решениям фазы (D49–D55, D63–D66, соглашение об именовании дисков D53); приёмочный прогон полного
  сюита (`--no-tia`, D8) — фаза P6 терминальна после закрытия.
- **Follow-up (Pending Work P6.3, не блокирует P6.5/P6.6):** `FormatBenchRunner::producePdfAlt()`,
  `GoldBuildCommand`, `FixtureForm`, `FixturesVerifyCommand` читают конфиг-ключи диска напрямую
  (allowlisted гейтом с причиной, периметр D115 их не назвал) — перевод на резолвер вне Scope
  P6.3, отдельный follow-up item.
- **`## Phase Handoff` фаз P2 и P5 не собран** («—» в `phases/P2.md`/`phases/P5.md`) — висящий
  bookkeeping-хвост, штатно закрывается `/task:plan-close <ID> P2` / `P5`; продолжение плана НЕ
  блокирует.
- **Прочие follow-up без изменений**: `ExtractionOrchestrator` теряет семь старых полей паспорта
  (P5.5); `EstimateValidator::bcadd()` научная нотация (P5.4); `RerunRangeAction` не вызывает
  `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без писателя (B-4); `RerunRangeAction`
  work_type_id preserve-ветка без теста (P5.5); систематический timeout `EST-XLSX-KS2-001` (P5.7);
  партиционный дрейф `pg_partman` (`prc_events`), `memory_limit`-конфликт контейнера
  `agelto_app`, нерасследованный arch-тест `CoreKnowsNoDocumentTypeTest` (P5.8).

**Sources of truth:**

- `research/07-storage-addressing.md` — вердикты, контракты, фолбэк, backfill и процедура cutover
  целиком; §8 — источник текста runbook'а P6.6.
- `findings/P6-file-carriers.md` — факты дерева со строками (слой 1), на которых стоят вердикты.
- `phases/P6.md` — Phase Context, ТЗ P6.6 (D114–D116), Completion Notes P6.1–P6.5.
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items.

**Open risks:**

- **`estimates_s3` объявлен и никем не адресуется** (`config/filesystems.php:74-85`) — мёртвое
  объявление, владелец не назначен.
- **Перевод корпуса эталонов на S3 сломает `GoldCorpusBuilder`** (`:162` вызывает `->path()`,
  которого у S3-драйвера нет) — независимо от адресации; решение вне плана.
- **10 упавших тестов приёмочного прогона P5.8** — партиционный дрейф `pg_partman`,
  `memory_limit`-конфликт `agelto_app`, один arch-тест, permission denied на `resources/`.
  Владелец не назначен; P6.6 будет гонять ПОЛНЫЙ сюит и упрётся в них (подтверждено на P6.3/P6.4/
  P6.5 — тот же состав из 10, новых падений от диффа P6.5 нет).
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7) —
  после `migrate:fresh --seed` P6.2 датасет восстановлен НЕ был (вне Scope P6.2–P6.5); следующему
  item'у, которому нужен датасет, восстанавливать `estimate:gold:import --prepare`.
- **`app/Filament/Resources/Procurement/** ` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife*`, `.swissknifeman/`,
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений;
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …` (подтверждено действующим на P6.3–P6.5, действует и дальше по P6).
- **PHPStan в контейнере `agelto_app` падает на дефолтном 256M лимите** (`memory_limit`-конфликт,
  P5.8) — обход `--memory-limit=1G`, подтверждено на P6.3–P6.5, действует и дальше по P6.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app` вместо `docker compose exec` (`MINIO_ROOT_PASSWORD`);
  `phpstan analyse --memory-limit=1G` вместо дефолта — без изменений, см. Open risks.
- **Deferred:** `## Phase Handoff` P2/P5 (нужен `plan-close`); писатель `verified_at`/`verified_by`;
  писатель пяти колонок `source_*`; прокидка семи старых полей паспорта в
  `ExtractionOrchestrator`; материализация не-`Item` строк; тип нормы аксессором из `code`;
  типизированный носитель `RowType` (OQ-6); переписывание текстов промтов сверх дедупликации
  (OQ-7); партиционирование `prc_events`/`llm_calls`; `RerunRangeAction` `replaceResources()`;
  научная нотация в `EstimateValidator::bcadd()`; тест на preserve-ветку `work_type_id`;
  персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф `pg_partman` под
  тестовые даты, `memory_limit`-конфликт `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`;
  судьба мёртвого диска `estimates_s3`; колонка диска для `prc_ai_dataset_examples.gold_ref`
  (заводится вместе с писателем, если он появится); перевод `FormatBenchRunner`/`GoldBuildCommand`/
  `FixtureForm`/`FixturesVerifyCommand` на `FileLocationResolver` (Pending Work P6.3); реальное
  удаление объектов на исходном диске после окна отката (P6.5 Scope Excluded — шаг runbook'а P6.6).
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113.
