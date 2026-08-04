# HANDOFF — 2026-08-04 — after P6.1

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — класс «migrations» всегда full (§3 Routing P6.2) |
| Context | continue (/clear) — ручной item |
| Суть | P6.2 — три миграции колонки диска (`prc_document_versions`, `prc_ai_fixtures`, `prc_estimates.raw_result_disk`), модели, фабрики, тест |
| Команда | — |

Код-блок ниже несёт полную D18-форму:

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P6.2
```

**Сделано этим заходом (`plan-run`, opus/high, 2026-08-04).** P6.1 закрыт 🟢 Done, item-коммит
`925bc9b` (4 файла, все под `plans/2026.07.31-SYS-INTEGRITY/**`: три из `Files` + скрипт
инвентаря `artifacts/collect-storage-carriers.py`, разрешённый Code Guidance item'а):

- **Машинный инвентарь** (`findings/P6-file-carriers.md` + `artifacts/storage-carriers.json`):
  12 колонок-кандидатов из миграций (включая сырой SQL партиционированных таблиц — греп по
  `$table->text` их не видит), 8 носителей + 4 не-носителя, writer/reader-пути по каждому,
  числа retention и ILM. Скрипт сбора сам проверяет полноту и падает ненулевым кодом, если
  инвентарь отстал от схемы; на `925bc9b` — `unlisted_columns: []`, exit 0.
- **Три поправки к разведке `repo-recon` §12** — ровно то, ради чего требовался встречный греп:
  у `prc_document_versions.storage_path` **пять** writer-путей, а не один (`DocumentDownloader`,
  `WorkingCopyProvisioner`, `DatasetImporter`, `ArchiveStrategy`, `GsfxStrategy`); рабочая копия
  эталона — не «файл без записи», а пятый из них; потребителей резолвера пять, а не четыре
  (`DatasetInputBuilder:95` дублирует фолбэк в обход).
- **Находка о прецеденте:** `prc_document_representations.disk` заведена верно, но **семь боевых
  writer-call-site'ов пишут литеральный `null`**; реальное имя диска пишет один call-site
  бенчмарка. Приём «диск в записи» на дереве не работает даже там, где схема его несёт.
- **Вердикты** (`research/07-storage-addressing.md` §2, критерий D50 в проверяемой форме В1 ∧ В2):
  колонка нужна трём носителям — версии документа, эталону корпуса и **`prc_estimates.
  raw_result_ref`** (D114, расхождение с пред-вердиктом D50); пяти — не нужна, каждому с причиной
  словами.
- **Контракты и процедура:** `FileLocation` + `FileLocationResolver` с явным списком «что НЕ
  делает»; автомат фолбэка (срабатывает только на отсутствие объекта, не на сбой драйвера);
  псевдокод backfill с порядком «верифицировал → обновил строку»; соглашение об именах дисков;
  **cutover из десяти шагов** с точкой отката на каждом.
- **Task Contract**: `pre_mutation: allow` · `pre_final: allow` (route `frontier/high`, точное
  совпадение с Routing P6.1 — не перебор).

Полные числа и разбивка — `phases/P6.md` P6.1 Completion Notes.

**Status:** 🟢 Done. Known Deviations — нет.

**Три новые D-записи, которые обязан прочитать исполнитель P6.2–P6.6:**

- **D114** — критерий D50 операционализирован (В1: запись адресует файл И у неё есть живой
  писатель; В2: строки одного носителя могут указывать в разные поколения диска), и по нему
  `prc_estimates` получает колонку `raw_result_disk`. Буквальный D50 на фактах дерева вырождался:
  окно отката — недели, самый короткоживущий блоб живёт 90 дней, то есть «(б)» выполнялось у всех
  семи носителей.
- **D115** — периметр writer/reader-путей P6.3 расширен: пять писателей версий, семь
  writer-call-site'ов представлений (пишут `null`), пятый обходчик резолвера.
- **D116** — cutover из десяти шагов; **переименование дисков в поколенческие имена (D53) стоит
  ДО материализации `null` (D51)**, иначе материализация зафиксирует `s3` в тысячах строк и
  превратит переименование в миграцию данных. Плюс шаг дельта-синка перед флипом конфига.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 1/6** 🟡 In progress — закрыт research-item P6.1; фаза открыта.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P6, очередь items —
  `P6.1 → P6.2 → P6.3 → P6.4 → P6.5 → P6.6` (номер = очередь, зависимости линейны:
  каждый следующий потребляет Deliverables предыдущего).
- **ТЗ P6.2–P6.6 приведено к вердиктам P6.1** (D114–D116) — читать `phases/P6.md` в текущей
  редакции, не по памяти: изменились `Scope Included`/`Files` P6.2 и P6.3, `Code Guidance` P6.4,
  `Scope Included` P6.5, `Scope Included`/`Validation` P6.6.
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
  целиком; **единственный источник состава миграций P6.2 и периметра P6.3**.
- `findings/P6-file-carriers.md` — факты дерева со строками (слой 1), на которых стоят вердикты.
- `artifacts/storage-carriers.json` + `artifacts/collect-storage-carriers.py` — машинный индекс и
  воспроизводимая команда сбора; пересобирается на любом дереве.
- `phases/P6.md` — Phase Context, ТЗ P6.2–P6.6 в редакции D114–D116, Completion Notes P6.1.
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items.

**Open risks:**

- **Периметр P6.3 больше, чем выглядел в скелете** (D115): ~20 файлов вместо 9. Item остаётся
  одним — гейт-тест обязан лечь вместе с миграцией писателей, иначе перевод остаётся
  негейтированным; но объём стоит учитывать при планировании захода.
- **`estimates_s3` объявлен и никем не адресуется** (`config/filesystems.php:74-85`) — мёртвое
  объявление, владелец не назначен.
- **Перевод корпуса эталонов на S3 сломает `GoldCorpusBuilder`** (`:162` вызывает `->path()`,
  которого у S3-драйвера нет) — независимо от адресации; решение вне плана.
- **10 упавших тестов приёмочного прогона P5.8** — партиционный дрейф `pg_partman`,
  `memory_limit`-конфликт `agelto_app`, один arch-тест. Владелец не назначен; P6.6 будет гонять
  ПОЛНЫЙ сюит и упрётся в них.
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7) —
  P6.2 в Validation несёт `migrate:fresh --seed`: датасет восстанавливать
  `estimate:gold:import --prepare` после прогона.
- **`app/Filament/Resources/Procurement/** ` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife*`, `.swissknifeman/`,
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений;
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …` (действует и для P6).

**Workarounds/Deferred/Open questions:**

- **Workarounds:** те же, что в предыдущем handoff'е (см. Open risks) — без изменений.
- **Deferred:** `## Phase Handoff` P2/P5 (нужен `plan-close`); писатель `verified_at`/`verified_by`;
  писатель пяти колонок `source_*`; прокидка семи старых полей паспорта в
  `ExtractionOrchestrator`; материализация не-`Item` строк; тип нормы аксессором из `code`;
  типизированный носитель `RowType` (OQ-6); переписывание текстов промтов сверх дедупликации
  (OQ-7); партиционирование `prc_events`/`llm_calls`; `RerunRangeAction` `replaceResources()`;
  научная нотация в `EstimateValidator::bcadd()`; тест на preserve-ветку `work_type_id`;
  персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф `pg_partman` под
  тестовые даты, `memory_limit`-конфликт `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`;
  **новое (P6.1)** — судьба мёртвого диска `estimates_s3`; колонка диска для
  `prc_ai_dataset_examples.gold_ref` (заводится вместе с писателем, если он появится).
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113.
