# HANDOFF — 2026-08-05 — after P4.9

**Next:** manual: implementation/high — item P4.6 (механизм отслеживания изменений: перекрытие
окон, delta-scan, метрика отставания; ТЗ пересобрано под группу А без миграций, D118/D122).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — предписано §3 Routing (`implementation/high`) |
| Context | continue (/clear) — ручной item |
| Суть | Реализовать перекрытие окна backlog-рескана (`window_overlap_days`), новый delta-scan (процессор + команда `crawler:run-delta` + регистрация под флагом), метрики `scan_gap_seconds`/`oldest_update_seen` в `prc_crawler_runs.stats`, дедуп строк блока по `registryNumber`, стартовый ценовой стек из поля профиля |

```text
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P4.6
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-05) — P4.9 закрыт 🟠, item-коммит
`c5add62`.**

- **Живая проба P4.9 подтвердила все четыре константы П-6** (`PROCUREMENT_METHOD_COLUMN`/
  `INITIAL_PRICE_COLUMN`/`OKPD2_COLUMN`/`CUSTOMER_NAME_COLUMN`) — ноль расхождений с ожиданием
  `research/05` §3.2, включая оба «вероятных» (Р-14/Р-15). Полная строка из 25 заголовков и
  таблица «ожидалось → снято» — `findings/P4-csv-headers.md`, `artifacts/eis-csv-headers.json`.
  Проба шла через RU-прокси `prc_proxies` id=1 (тот же узел, что P4.1), MCP Playwright с
  `ignoreHTTPSErrors`, боевой URL собран РЕАЛЬНЫМ `EisCsvUrlBuilder` на засеянном профиле P4.5.
- **Код: П-6/П-7/П-8/П-10 закрыты.** `EisCsvRowMapper` — четыре константы по факту;
  `EisProcedureType` — 9/9 наблюдённых способов (было 3/9); `CsvSourceMethod`/`EisCardUrlBuilder`
  резолвят карточку по `procedure_type_raw`, не по нормализованному enum (П-8); `EisCardUrlBuilder::
  buildUrl()` различает явный маркер `null` (вкладки нет по природе, D121) от отсутствующего ключа
  (ошибка конфигурации) — `EisPlatformSeeder` заводит маркер для `ea615`/`po615`.
- **Найдена и снята пятая, незадокументированная причина тихого отказа резолва карточки:**
  реестровый номер несёт префикс «№» (U+2116) у 500/500 строк пробы — виден уже в сырой фактуре
  P4.1, но не был назван дефектом маппера. Без снятия ЛЮБАЯ карточка резолвилась бы по номеру,
  не совпадающему ни с одним примером разведки. `EisCsvRowMapper::stripRegistryNumberPrefix()`.
- **Фикстура `eis-block-win1251.csv` пересобрана** из реального тела пробы (4 строки, четыре
  разных способа закупки); красная проба маппера до правки процитирована в Completion Notes
  item'а (`git stash` четырёх app-файлов → 7/12 тестов падают → `git stash pop`).
- **Material-отклонение (🟠), полностью названо:** item-коммит несёт дифф вне `Files` — докблок
  `Procurement::$procedure_type_raw` (легальный рычаг против `phpstan-baseline.neon`, требуется
  правкой П-8), сама строка `phpstan-baseline.neon` (минус одна, не рост), и два теста
  (`FetchDetailsTest`/`DetailAnalysisProcessorTest`), чьи фикстуры несли `procedure_type`/
  нормализованный `procedure_url_types`-ключ и регрессировали бы 6 уже зелёных тестов после П-8 —
  правка обоих механическая (ключи фикстуры под новую сигнатуру, ассерты не менялись).
- **Validation-клауза «непусты … `okpd2_code`» на реальной фикстуре — честный `null`, не
  дефект:** живая проба (500 строк архивного профиля, завершённые закупки) не несёт классификации
  ОКПД2/ОКПД/ОКДП/КТРУ ни у ОДНОЙ строки — источник не заполняет её на этом срезе данных
  (заголовок колонки подтверждён отдельно). Синтетический тест «ОКПД2 разбирается…» продолжает
  покрывать логику разбора непустого значения.
- **Полный сюит на item-коммите — 10 failed / 1040 passed, K=0:** байт-в-байт документированная
  база (`pg_partman` ×4, `CoreKnowsNoDocumentTypeTest` ×2, `PromptApplyTest` ×2, `CostReportTest`
  ×1, `TextGridRendererTest` ×1); один `pg_partman`-провал сместился на новую строку
  (`DetailAnalysisProcessorTest > run наполняет…` — стал доступен глубже пайплайна после починки
  П-8), верифицировано отдельным прогоном на дереве БЕЗ единой строки диффа item'а (тот же тест
  красный и там) — не новый дефект, дочинивается P7.4.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 2/9** 🟡 In progress — P4.1/P4.2 закрыты 🟢, P4.3/P4.4/P4.5/**P4.9** закрыты 🟠, P4.6/P4.7/
  P4.8 открыты.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P4, очередь остатка —
  **P4.6 → P4.7 → P4.8**.
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `/task:plan-design <ID> finish`:
  контракт фазы появился после последнего `finish`, и `plan-protocol` §5 требует сквозного
  reconcile, а не точечной правки. До этого P8 не исполняется (`Exec = plan-design`).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close`
  (`/task:plan-close 2026.07.31-SYS-INTEGRITY P6`) — висящий bookkeeping-хвост, продолжение плана
  НЕ блокирует.
- **Р-8 и Р-4 — за пределами фазы** (`research/05` §3.4): `max_blocks_per_window = 100`
  недостижим, а его ветка при срабатывании теряет ценовой интервал без дробления. Кандидаты в
  `open-questions.md`.
- **Из P4.4/P4.5 (Pending Work items'ов):** `options.records_per_page` (мёртвый дефолт
  `CrawlerFactory`, читателя нет) при правке профиля через форму P4.4 больше не сохраняется —
  `options.columns` единственный managed-ключ; оператор, отредактировавший профиль в админке,
  теряет правку при повторном `db:seed --class=CrawlerProfileSeeder`.
- **Из P4.9 (Pending Work item'а):** `IKZ_COLUMN` — фактический заголовок пробы БЕЗ суффикса
  «(ИКЗ)», вне четырёх констант П-6, не правился; `okpd2_code` на реальной фикстуре легально
  `null` (источник не заполняет классификацию на этом срезе данных) — не дефект маппера.
- **Прочие follow-up без изменений**: `ExtractionOrchestrator` теряет семь старых полей
  паспорта (P5.5); `EstimateValidator::bcadd()` научная нотация (P5.4); `RerunRangeAction` не
  вызывает `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без писателя (B-4);
  `RerunRangeAction` work_type_id preserve-ветка без теста (P5.5); систематический timeout
  `EST-XLSX-KS2-001` (P5.7); партиционный дрейф `pg_partman` (`prc_events` — теперь ВИДЕН и в
  `DetailAnalysisProcessorTest`, дочинивается P7.4), `memory_limit`-конфликт контейнера
  `agelto_app`, нерасследованный arch-тест `CoreKnowsNoDocumentTypeTest` (P5.8); четыре
  потребителя конфиг-ключа диска в обход резолвера (Pending Work P6.3).

**Sources of truth:**

- `phases/P4.md` — Phase Context, контракт фазы, ТЗ P4.1–P4.9, P4.9 Completion Notes/Known
  Deviations (полный разбор дифф-вне-Files и honest-null по ОКПД2).
- `phases/P8.md` — скелет новой фазы с контракт-блоком; предмет, что уже есть в проекте и чего нет.
- `findings/P4-csv-headers.md` + `artifacts/eis-csv-headers.json` — живая проба P4.9: полная
  строка из 25 заголовков, `expected_vs_observed` четырёх констант, три дополнительные находки
  (префикс «№», пустой ОКПД2 в архивном срезе, заголовок ИКЗ без суффикса).
- `research/05-change-tracking.md` — синтез P4.2: gap-таблица, перекрытие и календарь, перечень
  правок §3.1 (группа А → P4.6) и §3.2 (группа Б → P4.9, ЗАКРЫТА), §3.4 (что не чинится в фазе),
  контракт фильтра, две таблицы профилей, план прогона, метрики, калибровочные вопросы.
- `findings/P4-eis-recon.md` — фактура ЕИС 2026-08-04 (слой 1); §8 — одиннадцать расхождений
  «наблюдённое ↔ код» (пять из них — П-6/П-7/П-8/П-10 — закрыты этим заходом).
- `artifacts/eis-filter-params.json` — 25 флагов колонок и их подписи.
- `artifacts/eis-registry-settings.json` — `procedure_url_types` (девять сырых меток — эталон
  покрытия П-7, ЗАКРЫТО) и `card_url_templates` с `_observed`.
- `brief/01-refinements.md` блок 2026-08-05 — вход владельца: обе развилки и новое требование по
  деградированным сметам с трассировкой «мысль → куда ушла».
- `plan.md` §5 D118–D122 — решения захода `plan-design`, предшествовавшего этому item'у;
  `roadmap.md` — очередь и место P8.

**Open risks:**

- **`Классификация по ОКПД2` пуста у ВСЕХ строк архивного среза 500-строчного блока пробы** —
  источник не заполняет классификацию на уровне листинга для завершённых закупок 44-ФЗ этого
  окна. Не блокирует P4.6/P4.7 (мапится корректно, когда данные есть), но `okpd2_code`/
  `okpd2_codes` могут оставаться пустыми систематически на боевом прогоне — числовой масштаб
  снимается только боевым прогоном P4.7.
- **`pg_partman` (`prc_events`)** — партиции не покрывают тестовые даты; теперь виден в НОВОМ
  месте (`DetailAnalysisProcessorTest > run наполняет…`, помимо трёх `SupplierResultsProcessor`);
  чинится инструментовкой P7.4.
- **Сертификат ЕИС не проверяется дефолтным набором корней** (`ERR_CERT_AUTHORITY_INVALID` —
  российский УЦ). Для боевого Guzzle нужна политика (Р-10) — стоп-сигнал С-5 плана прогона.
  Владелец 2026-08-04: ставить сертификаты только если начнутся проблемы.
- **Дневной темп сдвига выдачи не измерен** — час delta-scan (02:00 МСК) выбран по единственному
  ночному измерению, K-7.
- **223-ФЗ проработан только как листинг** — детальная стадия требует `noticeGuid`, которого в CSV
  нет, способ добора разведкой не проверялся (Р-11).
- **`estimates_s3` объявлен и никем не адресуется** (`config/filesystems.php:74-85`); перевод
  корпуса эталонов на S3 сломает `GoldCorpusBuilder` (`:162` вызывает `->path()`).
- **10 упавших тестов приёмочного прогона** (подтверждено на `c5add62`, тот же список ×
  `DetailAnalysisProcessorTest` вместо смещения счётчика): `pg_partman` (`prc_events`, 4),
  `CoreKnowsNoDocumentTypeTest` (2), `permission denied` на `resources/` (`PromptApplyTest`, 2),
  `CostReportTest` (1), `memory_limit` `TextGridRendererTest` (1).
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7).
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker compose` падает на интерполяции, обход
  `docker exec agelto_app …`.
- **PHPStan в контейнере `agelto_app` падает на дефолтном 256M лимите** — обход `--memory-limit=1G`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app` вместо `docker compose exec`; `phpstan analyse
  --memory-limit=1G`; `ignoreHTTPSErrors` для разведки ЕИС (сертификат); `Registry::getAttribute
  ('code')` вместо `->code` в новом коде (докблок сломал бы существующую baseline-запись).
- **Deferred:** детализация фазы P8 и повторный `finish`; `## Phase Handoff` P2/P5 и формальный
  `plan-close` P6; Р-8 (safety-cap ценового дробления) и Р-4 (ловушка стартового стека `0:0`);
  детальная стадия 223-ФЗ (Р-11); резервный RU-прокси-узел; `fz223` в карте
  `config('crawler.filters')`; докблок `@property string $code` у `Registry` + парная чистка
  baseline; переименование `crawler.archive.date_format` → `crawler.eis.date_format`; писатель
  `verified_at`/`verified_by`; писатель пяти колонок `source_*`; прокидка семи старых полей
  паспорта в `ExtractionOrchestrator`; материализация не-`Item` строк; тип нормы аксессором из
  `code`; типизированный носитель `RowType` (OQ-6); переписывание текстов промтов сверх
  дедупликации (OQ-7); партиционирование `prc_events`/`llm_calls`; `RerunRangeAction`
  `replaceResources()`; научная нотация в `EstimateValidator::bcadd()`; тест на preserve-ветку
  `work_type_id`; персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф
  `pg_partman`, `memory_limit`-конфликт `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`; судьба
  мёртвого диска `estimates_s3`; колонка диска для `prc_ai_dataset_examples.gold_ref`; перевод
  `FormatBenchRunner`/`GoldBuildCommand`/`FixtureForm`/`FixturesVerifyCommand` на
  `FileLocationResolver`; реальное удаление объектов на исходном диске после окна отката;
  операторская правка `options.records_per_page` через форму профиля; сохранение других ключей
  `options` сверх `columns` при правке профиля через форму; историческая витрина числа дублей
  блока (D122 — счётчик живёт в `FetchResult` и логе, колонки в `prc_fetches` нет); заголовок
  `IKZ_COLUMN` без суффикса «(ИКЗ)» (P4.9 Pending Work); систематически пустой `okpd2_code` на
  архивном срезе — числовой масштаб снимается P4.7.
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
