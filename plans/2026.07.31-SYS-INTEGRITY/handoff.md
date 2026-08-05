# HANDOFF — 2026-08-05 — after P4.16

**Next:** `plan-exec: implementation/low` — исполнить **P4.8** (синхронизация доков домена и
приёмочный прогон фазы). Единственный оставшийся item фазы P4 — Inputs ждали Completion Notes всех
предыдущих items P4.1–P4.7 и P4.10–P4.16, все терминальны.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — пин команды `plan-exec` |
| Context | continue (/clear) — ручной item |
| Суть | Синхронизировать доки домена (`docs/02_Workflow/crawling-and-discovery.md`,
`docs/03_Dev/crawling-pipeline.md`, `docs/03_Dev/document-pipeline.md`) под фактическое поведение
фазы P4 и провести приёмочный прогон фазы (`--no-tia`) |

```
/task:plan-exec 2026.07.31-SYS-INTEGRITY P4.8
```

**Сделано этим заходом (`plan-run P4.16`, sonnet/high, 2026-08-05).**

- **P4.16 закрыт 🟠 Done with deviations**, item-коммит **`110c550`** (двумя атомарными коммитами:
  `58149bd` код+тесты+findings, `110c550` — правка плейсхолдера хеша в findings на реальный).
- **Корень Д-8 починен:** `CsvSourceMethod::request()` больше не передаёт пустой массив
  `queryParams` в `->get()` (Guzzle замещал им query-строку карточного URL, стирая
  `?regNumber=…`) — вариант A `research/10` §3.9, листинговый путь с непустыми `queryParams` не
  тронут (закреплено существующим тестом).
- **Сторож «пришла не та страница»:** `CsvSourceMethod::assertCardPage()` — `Log::warning` на
  любой вкладке при `$response->failed()` или отсутствии реестрового номера в теле;
  `CardPageMismatchException` (новый класс) — только на `common-info`, ловится штатным
  `catch (Throwable)` `DetailAnalysisProcessor::run()` → `detail_last_error` + backoff,
  `markDetailFetched` не ставится.
- **`CommonInfoProcessor::isEmpty()`** — не пишет `prc_eis_details`, когда разбор полностью пуст
  (все атрибуты `null`, нет строк финансирования, нет позиций); существующие строки не удаляются.
- **Красный тест «до» предъявлен и подтверждён «красное без фикса»:**
  `CardCollectDetailsTest > collectDetails отправляет карточный URL с query-строкой regNumber…`
  падал на исходном коде (`An expected request was not recorded`), `git stash` дал тот же красный
  результат повторно.
- **Живая приёмка (D131) на восьми карточках §9.5** (`findings/P4-detail-contour-live.md`):
  `prc_documents` **64**, `prc_document_versions` **60**, `prc_eis_details` непустых **8/8**,
  `prc_items` **76**, `prc_financing` **24**, `prc_ai_analysis_runs`/`llm_calls` — **0 → 0**.
  `prc_procurement_parties` осталась **0** на всех восьми — Validation-критерий НЕ выполнен, но
  это подтверждённый факт вёрстки источника (закрывает [UNVERIFIED] P4.14), а не дефект транспорта
  — отсюда `🟠`.
- **Новый факт вне Scope, найден живой приёмкой:** `prc_events` (pg_partman) не покрывает
  исторические даты реальных карточек — `EventJournalProcessor` падает `SQLSTATE[23514]` на
  восьми из восьми, из-за чего `markDetailFetched` не достигается ни на одной (данные,
  записанные раньше в `run()`, не теряются). Блокирует эксплуатационно ЛЮБУЮ реальную закупку с
  историческими событиями — backoff-цикл до закрытия **P7.4**. Не чинилось этим item'ом
  (Implementation Rules п. 8 + Scope Excluded).
- **Инфраструктурная поправка окружения (не код):** `agelto_minio` пересоздан с
  `MINIO_ROOT_PASSWORD`, совпадающим с уже сконфигурированным секретом приложения
  (документированный Known workaround) — без неё `DocumentDownloader` падал на проверке
  существования объекта. Побочный эффект: три теста `StorageLifecycleTest`, ранее падавшие на
  несовпадении подписи, теперь зелёные (полный сюит — 10 упавших вместо 13).
- **Диф item-коммита вне `Files`:** `tests/Feature/Console/FetchDetailsTest.php` — синтетический
  `registry_number` трёх тестов выровнен с реальным номером фикстуры `common-info.html`
  (`0338200009125000001`), тем же классом правки, что `CardCollectDetailsTest`/
  `DetailAnalysisProcessorTest` — без этого новый сторож ловил бы несоответствие на
  уже существующем, не входящем в `Files` тесте.
- Синхронизированы `plan.md` (§6 Update Log), `phases/P4.md` (Phase Status, item P4.16 Status/
  Completion Notes/Pending Work/Known Deviations/write-site), `journal.jsonl`.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 5/16** 🟡 In progress — терминальны 15 из 16 (P4.1 🟢, P4.2 🟢, P4.3 🟠, P4.4 🟠, P4.5 🟠,
  P4.9 🟠, P4.6 🟠, P4.7 🟠, P4.10 🟢, P4.11 🟢, P4.12 🟢, P4.13 🟠, P4.14 🟠, P4.15 🟠, P4.16 🟠);
  открыт только **P4.8**.
- **P7 0/8** ⬜ Not started — ждёт терминальной P4.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Остаток P4 — только **P4.8**
  (`implementation/low`, `plan-exec`), последний item фазы (D123/D129).
- После P4.8 — фаза P4 терминальна, план переходит к **P7** (последняя фаза очереди D63).
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **`prc_events`/pg_partman не покрывает исторические даты** (новый факт P4.16, §3.1 findings) —
  блокирует `markDetailFetched` на реальных закупках с историческими событиями; вход **P7.4**,
  приоритет выше, чем «источник флейка сюита» — теперь ещё и продовый блокер.
- **Расширение окна прогона и включение delta-scan/fresh** (D126) — решение владельца, вход
  готов: P4.16 предъявила живые числа документного контура.
- **База «упавших тестов» полного сюита — 10** (было 13; три `StorageLifecycleTest` закрыты
  побочным эффектом MinIO-поправки P4.16). Состав: `CoreKnowsNoDocumentTypeTest`×2,
  `CostReportTest`, `PromptApplyTest`×2, `GeminiProxyRoutingTest`, `TextGridRendererTest`,
  `SupplierResultsProcessorTest`×3 (`pg_partman`-флейк, тестовая БД).
- **Серия M-5 (60 сэмплов) не снята** — дефект скрипта-сэмплера; пересдать нечем до следующего
  боевого прогона.

**Sources of truth:**

- `phases/P4.md` item **P4.8** — ТЗ целиком, последний item фазы.
- `phases/P4.md` item **P4.16** Completion Notes/Pending Work — фикс Д-8, живые числа, два новых
  факта (partman-гэп, parties=0 подтверждён).
- `findings/P4-detail-contour-live.md` — живая приёмка целиком (числа, команды, дерево).
- `plan.md` §6 Update Log (строка P4.16), §4 Phase Index & Status Board.

**Open risks:**

- **`prc_events`/pg_partman не покрывает исторические даты** — см. Remaining; вход P7.4,
  эксплуатационный блокер повторных ре-фетчей детального слоя на реальных закупках.
- **`prc_procurement_parties` подтверждённо пуст** на выборке 4 типов процедуры — организационный
  блок отсутствует в текущей вёрстке `common-info`; роли/организации нужно искать в другом
  источнике (вне Scope P4.16), если понадобятся P5/P8.
- **`agelto_horizon` поднят** — после появления документов исполнит `PrepareDocumentJob`;
  AI-стадии за выключенными гейтами (подтверждено: `prc_ai_analysis_runs`/`llm_calls` не выросли).
- **Два типа процедуры вне `procedure_url_types`** (аудит бухотчётности, ТКО) — 0.16 % теряют
  детальную стадию.
- **Оценка корпуса для P7.3 — НИЖНЯЯ граница**: 2.61 КБ на закупку до P4.16; после появления
  документов/деталей вырастет — пересчёт входит в P7.3, не в P4.8.
- **`pg_partman` (`prc_events`)** — партиции не покрывают тестовые даты; чинится P7.4.
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7).
- **`estimates_s3` объявлен и никем не адресуется**; **бакет «Последующие годы» финансирования не
  заполняется**; **К-6 закрыт диагнозом (МСК), не кодом**.
- **Коллизия нумерации research:** `research/10-p4-remediation.md` и плановый
  `research/10-process-audit.md` (P7.6) делят номер `10` — файлы разные, отмечено в `index.md`.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — P4.16 выровняла ТЕКУЩИЙ запущенный `agelto_minio` с
  секретом приложения через одноразовый `MINIO_ROOT_PASSWORD=placeholder123 docker compose up -d
  minio` (не записано в `.env`); следующий `docker compose up`/`build` без этой переменной
  пересоздаст контейнер с требованием заново задать пароль — риск не закрыт файлом, только
  рантаймом текущего контейнера. **PHPStan в контейнере падает на дефолтном 256M** (обход
  `--memory-limit=1G`).

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app` вместо `docker compose exec`; `MINIO_ROOT_PASSWORD=<любое>
  docker compose build/up` (применено P4.16 — `agelto_minio` пересоздан с паролем, совпадающим с
  `filesystems.disks.s3.secret`); `phpstan analyse --memory-limit=1G`; `agelto_scheduler` остановлен;
  `Registry::getAttribute('code')` вместо `->code` в новом коде; `vacuum full` после массового
  удаления.
- **Deferred:** расширение окна прогона и включение delta-scan/fresh (D126); серия M-5;
  персистентность корня для ОСТАЛЬНЫХ каналов площадок; два типа процедуры вне
  `procedure_url_types`; стартовый ценовой стек от распределения; детализация фазы P8 и повторный
  `finish`; `## Phase Handoff` P2/P5 и формальный `plan-close` P6; Р-4 (ловушка стартового стека
  `0:0`); детальная стадия 223-ФЗ (Р-11); резервный RU-прокси-узел; `fz223` в карте
  `config('crawler.filters')`; докблок `@property string $code` у `Registry`; переименование
  `crawler.archive.date_format` → `crawler.eis.date_format`; писатель `verified_at`/`verified_by`;
  писатель пяти колонок `source_*`; прокидка семи старых полей паспорта в
  `ExtractionOrchestrator`; материализация не-`Item` строк; тип нормы аксессором из `code`;
  типизированный носитель `RowType` (OQ-6); переписывание текстов промтов сверх дедупликации
  (OQ-7); партиционирование `prc_events`/`llm_calls` (см. Open risks — теперь с продовым
  приоритетом); `RerunRangeAction` `replaceResources()`;
  научная нотация в `EstimateValidator::bcadd()`; тест на preserve-ветку `work_type_id`;
  персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф `pg_partman`,
  `memory_limit`-конфликт `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`; судьба мёртвого диска
  `estimates_s3`; колонка диска для `prc_ai_dataset_examples.gold_ref`; перевод
  `FormatBenchRunner`/`GoldBuildCommand`/`FixtureForm`/`FixturesVerifyCommand` на
  `FileLocationResolver`; реальное удаление объектов на исходном диске после окна отката;
  операторская правка `options.records_per_page` через форму профиля; историческая витрина числа
  дублей блока; заголовок `IKZ_COLUMN` без суффикса «(ИКЗ)»; `GeminiProxyRoutingTest`-assertion под
  URL-кодирование кредов P4.11; правка формы
  `FinancingRowData::fiscalYear`; явный пояс МСК в `EisCsvRowMapper` под К-6; поиск источника
  ролей/организаций закупки, если понадобятся P5/P8 (`prc_procurement_parties` подтверждённо пуст
  из `common-info` на выборке P4.16).
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
