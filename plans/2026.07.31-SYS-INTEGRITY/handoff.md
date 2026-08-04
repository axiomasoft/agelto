# HANDOFF — 2026-08-04 — after P6.2

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — инварианты writer-путей и единственной точки адресации, effort high+ обязателен (§3 Routing P6.3) |
| Context | continue (/clear) — ручной item |
| Суть | P6.3 — резолвер адреса (`FileLocation`+`FileLocationResolver`) как единственная точка чтения/записи; периметр расширен D115 (пять писателей версий, семь writer-call-site'ов представлений, пятый обходчик резолвера) |
| Команда | — |

Код-блок ниже несёт полную D18-форму:

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P6.3
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-04).** P6.2 закрыт 🟢 Done, item-коммит
`d4161b8` (10 файлов, все из `Files` item'а, посторонний дифф отсутствует):

- **Три новые миграции** по вердиктам P6.1/D114, форма по прецеденту `100055:33` (`text`,
  `nullable`, без `default`, без индекса, комментарий называет точный конфиг-ключ):
  `prc_document_versions.disk` (`crawler.documents_disk`), `prc_ai_fixtures.disk`
  (`ai.fixtures.disk`), `prc_estimates.raw_result_disk` (`crawler.raw_disk`). `down()` симметричен
  (`dropColumn`).
- **Три модели** — `disk`/`raw_result_disk` в `fillable` + докблок `@property string|null`, каст
  не заведён (строка).
- **Три фабрики** — дефолт `null` (историческая семантика), состояние `onDisk(string $disk)` во
  всех трёх (не булев флаг).
- **Тест** `tests/Feature/Document/DocumentVersionDiskColumnTest.php` — `test('колонка диска
  версии документа пишется и читается', ...)`, оба случая (`null` и явное имя).
- **Task Contract**: `pre_mutation: allow` · `pre_final: allow` (route `implementation/high`,
  точное совпадение с §3 Routing P6.2 — Routing предписывал `Exec=manual`, item исполнен
  `plan-run` инвертированным гейтом под фактическим маршрутом сессии, D75; не перебор).

Полные числа и разбивка Validation — `phases/P6.md` P6.2 Completion Notes.

**Status:** 🟢 Done. Known Deviations — нет.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 2/6** 🟡 In progress — закрыты P6.1 (research), P6.2 (миграции колонки диска); фаза открыта.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P6, очередь items —
  `P6.3 → P6.4 → P6.5 → P6.6` (номер = очередь, зависимости линейны: каждый следующий потребляет
  Deliverables предыдущего).
- **P6.3 периметр шире скелета** (D115): пять writer-путей `prc_document_versions.storage_path`
  (`DocumentDownloader::store()`, `WorkingCopyProvisioner`, `DatasetImporter`, `ArchiveStrategy`,
  `GsfxStrategy`), семь writer-call-site'ов `prc_document_representations.disk` (пишут `null`),
  пятый потребитель контракта резолвера (`DatasetInputBuilder:95`, обходит `RepresentationResolver`
  фолбэком `disk ?? config(...)`) — читать `research/07-storage-addressing.md` §4 в текущей
  редакции, не по памяти.
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
  целиком; §4 — контракт резолвера (единственный источник периметра P6.3), §3 — состав колонок,
  уже заведённых P6.2.
- `findings/P6-file-carriers.md` — факты дерева со строками (слой 1), на которых стоят вердикты.
- `phases/P6.md` — Phase Context, ТЗ P6.3–P6.6 в редакции D114–D116, Completion Notes P6.1/P6.2.
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items.

**Open risks:**

- **`estimates_s3` объявлен и никем не адресуется** (`config/filesystems.php:74-85`) — мёртвое
  объявление, владелец не назначен.
- **Перевод корпуса эталонов на S3 сломает `GoldCorpusBuilder`** (`:162` вызывает `->path()`,
  которого у S3-драйвера нет) — независимо от адресации; решение вне плана.
- **10 упавших тестов приёмочного прогона P5.8** — партиционный дрейф `pg_partman`,
  `memory_limit`-конфликт `agelto_app`, один arch-тест. Владелец не назначен; P6.6 будет гонять
  ПОЛНЫЙ сюит и упрётся в них.
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7) —
  после `migrate:fresh --seed` P6.2 датасет восстановлен НЕ был (вне Scope P6.2); следующему item'у,
  которому нужен датасет, восстанавливать `estimate:gold:import --prepare`.
- **`app/Filament/Resources/Procurement/** ` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife*`, `.swissknifeman/`,
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений;
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …` (подтверждено действующим на P6.2, действует и дальше по P6).
- **PHPStan в контейнере `agelto_app` падает на дефолтном 256M лимите** (`memory_limit`-конфликт,
  P5.8) — обход `--memory-limit=1G`, подтверждено на P6.2, действует и дальше по P6.

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
  (заводится вместе с писателем, если он появится).
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113.
