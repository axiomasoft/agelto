# FINDING (слой 1) — инвентарь репозитория против брейн-дампа

**Снято:** 2026-08-06 · **Как:** прямое чтение репо (Read/Grep/Bash), ветка `main`, коммит `172dd497`.
**Зачем:** брейн-дамп владельца писался «как будто с нуля»; значительная часть требований уже
реализована предыдущими планами (`2026.07.31-SYS-INTEGRITY`, `2026.07.28-EST-ARCH`). Без этого
инвентаря план породил бы дубли уже существующих механизмов.

Все факты ниже — `RAG:— (repo-grounded)`, источник указан файлом (и строкой, где проверялась).

---

## 1. Форматы документов (брейн-дамп блок 0) — РЕАЛИЗОВАНО

| Требование владельца | Носитель в коде | Состояние |
|:--|:--|:--|
| Excel → PDF | `app/Services/Procurement/Prepare/SpreadsheetStrategy.php` | ✅ PhpSpreadsheet (landscape + `fitToWidth(1)`) в ИЗОЛИРОВАННОМ процессе (`ConvertXlsxToPdfCommand`), фолбэк `LibreOfficeConverter`; второе представление `text_grid` (`TextGridRenderer`) |
| ODF / MS Word | `app/Services/Procurement/Prepare/WordStrategy.php` + `LibreOfficeConverter.php` | ✅ |
| PDF | `app/Services/Procurement/Prepare/PassthroughStrategy.php` | ✅ |
| Сканы / изображения | `ImageStrategy.php`, `ScanStrategy.php`, `OcrConverter.php` (ocrmypdf/Tesseract) | ✅ зонд текстового слоя по первым 3 страницам (`OcrConverter::TEXT_LAYER_SAMPLE_PAGES`) |
| Архивы (в т.ч. со сканами) | `ArchiveStrategy.php`, `Archive/ArchiveExtractor.php` | ✅ password-protected → `unprocessable` |
| ГрандСмета `.gsfx` | `GsfxStrategy.php`, `Gsfx/GsfxReader.php` | ✅ (сверх брейн-дампа) |
| Выбор представления | `RepresentationResolver.php`, таблица `prc_document_representations` | ✅ |

**Вывод:** блок 0 брейн-дампа — вопросы, а не задачи. Ответ: да, всё это уже умеем; Excel→PDF
сделан именно ради удобства LLM. В план входит только *проверка на боевом корпусе*, не разработка.

## 2. Колонки сметы (брейн-дамп блок 0-бис) — РЕАЛИЗОВАНО

`database/migrations/2026_07_04_100035_create_prc_estimate_items_table.php`:

| Требование | Колонка |
|:--|:--|
| № п/п | `source_position_number` (строка 50) |
| Обоснование | `code` (строка 31, «шифр ФЕР/ТЕР») |
| Наименование работ и затрат | `name` (строка 32) |
| Единица измерения | `unit` / `unit_base` / `unit_multiplier` (строки 33–35) |
| Количество всего с учётом коэффициентов | `quantity` (строка 36) + `attributes.modifiers[]` (строка 57) |

Подэлементы — **отдельная таблица** `prc_estimate_resources`
(`…100036_create_prc_estimate_resources_table.php`), как и просил владелец. Разделы —
`prc_estimate_sections`, итоги — `prc_estimate_totals`.

## 3. Профили обхода (блок 2) — ЧАСТИЧНО

| Требование | Состояние | Носитель |
|:--|:--|:--|
| Два вида профиля (архивный / актуальный) | ✅ enum | `App\Enums\Procurement\Crawler\CrawlerMode` = `fresh` \| `archive` |
| Процессоры режимов | ✅ | `Services/Procurement/Modes/{FreshRunProcessor,ArchiveRunProcessor,DeltaScanProcessor,BacklogRescanProcessor,DetailAnalysisProcessor}.php` |
| Сохранение состояния / продолжение с места падения | ✅ | `prc_crawler_runs.cursor` (`ListingCursor`), `CrawlerRunManager::startOrResume()` подхватывает `paused`-прогон |
| Один экземпляр на профиль | ✅ **на уровне БД** | частичный unique-индекс `prc_crawler_runs_crawler_id_running_unique` (миграция `…100039`, строка 43); менеджер ловит `UniqueConstraintViolationException` и резолвит гонку |
| Протухший heartbeat → `paused` (resumable) | ✅ | `config('crawler.heartbeat_timeout_seconds')` = 300 |
| Расписание (cron) в профиле | ✅ | `prc_crawlers.schedule`, регистрация в `routes/console.php` (только `mode=fresh` + `is_active`) |
| Статусы прогона | ✅ | `RunStatus` = `running`/`paused`/`completed`/`failed`/`cancelled` |
| **Кнопка «Запустить» / «Стоп» / «Продолжить» в админке** | ❌ **ГЭП** | `Filament/…/Crawlers/Tables/CrawlersTable.php` несёт только `EditAction` + `DeleteBulkAction` |
| **Сортировка (`sortBy`/`sortDirection`) в фильтре профиля** | ❌ **ГЭП** | `App\Dto\Procurement\Crawler\EisSearchFilter` полей сортировки не имеет (см. §5) |
| **Режим «только актуальные данные» (smart-stop по времени старта прошлого прогона)** | ❌ **ГЭП** | `FreshRunProcessor` знает cap-детект и `lastCompletedRun()`, но условия останова «дошли до `started_at` предыдущего прогона» нет |
| **Настройка «разрешён ли нахлёст»** | ❌ **ГЭП** | сейчас единственность жёсткая (индекс БД), переключателя в профиле нет |
| **Настройка «сохранять состояние или нет»** | ❌ **ГЭП** | cursor пишется всегда |
| **Настройка числа попыток и пауз в профиле** | ❌ **ГЭП** (глобально — есть) | `config('crawler.http.retries')` = 3, `crawler.throttle.min_ms/max_ms` = 1000/4000 — общие, не на профиль |
| Сиды двух профилей | ⚠️ проверить | `database/seeders/Procurement/CrawlerProfileSeeder.php` существует; состав против брейн-дампа не сверялся |
| История запусков + статистика в админке | ⚠️ частично | `Filament/…/Crawler/Runs/` (List/View/Infolist/Table) есть; состав статистики против брейн-дампа не сверялся |

## 4. Прокси, ретраи, троттлинг (блок 4, вторая половина) — В ОСНОВНОМ РЕАЛИЗОВАНО

- `prc_proxies` + `prc_proxy_targets` + `prc_proxy_checks`; единственная точка выдачи —
  `Services/Procurement/Proxy/ProxyResolver.php` по `ProxyScope`
  (`CrawlListing`/`CrawlDetail`/`DocumentDownload`/`Llm`).
- **Выбор узла — НЕ «случайный», а weighted-random внутри top-K** по score
  (`success_ratio`/`latency`/`streak`, EWMA) — `config('crawler.proxy.resolver')`. Это
  СИЛЬНЕЕ того, что просил владелец («рандомный прокси»), и покрывает его цель («следующую
  попытку — через новый прокси»).
- Circuit breaker двух уровней (`global`/`target`) с экспоненциальным cooldown и half-open
  probe-lock — `ProxyCircuitBreaker.php`, `config('crawler.proxy.breaker')`;
  `http_429` трактуется как троттлинг, не бан.
- Гео-политика по скоупу — жёсткий фильтр ДО скоринга (`crawler.proxy.policy`):
  `llm` запрещает `RU`, `crawl_*`/`document_download` требуют `RU`.
- Вежливая пауза между запросами — `Services/Procurement/PoliteThrottle.php`,
  `crawler.throttle.min_ms/max_ms`; калибровано боевым прогоном (194 блока, 0×403/429).
- **Toggle `use_proxy` и `proxy_country` в профиле** — ✅ есть (`prc_crawlers`, `CrawlerForm`).

**Гэпы блока 4:** (а) настройки попыток/пауз не пер-профильные и не пер-стадийные;
(б) «минимум перехлёста» между кроулингом и детальной стадией — общего бюджета запросов на
площадку нет; (в) healthcheck вёрстки — отсутствует полностью (см. §7).

## 5. ОКПД2-фильтр (блок 3) — ГЭП

- `App\Dto\Procurement\Crawler\EisSearchFilter` несёт: `stageApplications`/`stageCommission`/
  `stageCompleted`/`stageCancelled`, `priceFrom`/`priceTo`, `publishedFrom`/`publishedTo`,
  `searchString`, `morphology`. **ОКПД2 нет.**
- Справочник ОКПД2 в БД **есть**: `ref_okpd2` (`…100005`), модель `App\Models\Reference\Okpd2`,
  загрузка — `Console/Commands/Reference/LoadClassifiers.php`, резолв —
  `Services/Reference/ClassifierResolver.php`.
- Колонка CSV «Классификация по ОКПД2» (`scopeOkpd2Csv`) — уже в списке флагов
  `CrawlerForm::COLUMN_FLAG_LABELS`, т.е. ОКПД2 приходит в выгрузке и может служить
  пост-фильтром, даже если серверный фильтр окажется недоступен.
- Древовидной формы выбора в Filament нет.

## 6. Подача job'ов и анти-дубли (блок 5, первая половина) — ЧАСТИЧНО

- Мост crawling → пайплайн: `Services/Procurement/PipelineDispatcher.php` — два канала
  (немедленный `dispatch()` + everyMinute `dispatchBacklog()`), лимиты
  `ai.pipeline.dispatch_batch_limit` / `backlog_batch_limit` = 100 (`config/ai.php:402–403`).
  **То есть «100 000 job'ов сразу» уже не случится** — поллер берёт по 100 за тик.
- Анти-дубли на job'ах: `PrepareDocumentJob implements ShouldBeUnique` (:24),
  `ScreeningJob implements ShouldBeUnique` (:29),
  `EstimateExtractionJob implements ShouldBeUniqueUntilProcessing` (:33 — сознательно, из-за
  checkpoint-requeue self-dispatch, D39).
- Backoff/dead-letter: `Document::scopePendingRetry()` + `HasErrorBlock` +
  `pipeline:retry-failed` (everyTenMinutes).
- Наблюдаемость: `pipeline:stuck-check` (everyFifteenMinutes), `pipeline:status`.
- **ГЭП:** `crawler:fetch-details` **без аргумента обрабатывает ВЕСЬ due-набор
  `DetailRefetchSelector::due()` синхронно в одном вызове команды**
  (`Console/Commands/Procurement/FetchDetails.php:41–58`), каждые 5 минут. Ни лимита пачки,
  ни очереди, ни паузы между закупками внутри цикла здесь нет — это ровно тот
  «неравномерный перехлёст», о котором говорит владелец, и главный кандидат на риск бана.

## 7. Healthcheck площадки (блок 4, первая половина) — ГЭП

Аналогов нет. Есть `pipeline:stuck-check` (наблюдение за ЗАСТРЯВШИМИ документами) и
`prc_fetches` (лог HTTP-заборов), но проверки «структура ответа ЕИС не изменилась» —
ни таблицы, ни команды, ни события. `spatie/laravel-health` в зависимостях **не установлен**
(`composer.json` не содержит).

## 8. Статусы площадки и история изменений (блок 5, вторая половина) — В ОСНОВНОМ ЕСТЬ

- `prc_status_mappings` (`…100002`) + `StatusMappingSeeder` + `Services/Procurement/StatusMappingService.php`
  — статусы **пер-платформенные и сидируемые**, ровно как допускал владелец.
- `prc_revisions` (`…100012`) — ревизии закупки; `prc_events` (`…100013`) — журнал событий.
- Парсер журнала событий ЕИС — `Services/Procurement/Eis/Card/EventJournalParser.php`.
- **ГЭП:** доменного события «закупка перешла в положительный терминальный статус» и точки
  подписки на него нет; связки «завершена + есть распознанная смета» тоже нет.

## 9. Миграции (блок 5, хвост) — ГЭП

81 файл в `database/migrations/`. Из них «догоняющих» (add/drop колонки, доп-индексы,
partman-настройки) — ≥10, в т.ч. `…100048_add_procurement_foreign_to_prc_procurement_parties`,
`2026_07_08_100049_add_lease_columns_to_prc_documents`,
`2026_07_09_100050…100052`, `2026_07_27_100054_add_fixture_to_prc_ai_dataset_examples`,
`…100056_drop_prepared_storage_path_from_prc_document_versions`.
Осложняющие факторы схлопывания: `pg_partman` (`…100047`, `…100050`, `…100051`),
Pulse-таблицы (`2026_07_09_000001_create_pulse_tables`), `partitions:maintain`.

## 10. Три слоя смет и админка (блок 1) — ЧАСТИЧНО

- **Слой «золото» — файловая система:** `estimates/gold/*.gold.json` + `estimates/manifest.json`
  + `estimates/eval-splits.json` + `estimates/files/`; конфиг — `ai.dataset.gold_dir`
  (`config/ai.php:524`).
- **Слой «песочница» — БД:** `prc_ai_fixtures` (`…100053`, модель `Ai\Fixture`),
  `prc_ai_dataset_examples` (+ `fixture_id`, `…100054`).
- **Слой «боевые» — БД:** `prc_estimates` и потомки, `prc_ai_analyses`, `prc_ai_corrections`.
- Filament-ресурсы уже есть: `Ai/{Analyses,DatasetExamples,Fixtures,Prompts,PromptSuggestions,
  Providers,LlmRoutes}`, `Estimates/EstimateResource`, страница `FinalPromptPage`,
  виджет `LlmSpendChartWidget`.
- **ГЭП:** содержимое `*.gold.json` (файловая система) в админке не отображается — нет
  read-only витрины «что лежит в золотой смете». Владелец просил именно её.

## 11. Документация (блок 6) — ГЭП

- `docs/` — `00_Ideas`, `01_Business`, `02_Workflow`, `03_Dev` (+ `adr`, `ai`, `ai-context`,
  `docker`, `process`, `runbooks`), `04_Operators`, `dev`, `meta`, `specs/v2` (9 файлов).
- `docs-private/` — `ai-economics.md`, `crawler-gotchas.md`, `local-llm-training.md`,
  `open-follow-ups.md`, `prompt-engineering-notes.md`, `risk-register.md`, `history/`.
- Слияния нет.
- Диаграммы **частично есть** (mermaid найден в `docs/03_Dev/{llm-providers,state-machines,
  document-pipeline}.md`, `docs/03_Dev/ai/{prompts,training-loop}.md`,
  `docs/03_Dev/ai-context/index.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`).
  Отсутствуют они там, где владелец просил больше всего: **кроулинг** —
  `docs/02_Workflow/crawling-and-discovery.md` и `docs/03_Dev/crawling-pipeline.md` диаграмм
  не несут. Задача блока 6 — не «завести диаграммы с нуля», а достроить недостающие и свести
  два дерева доков в одно.
- `docs/dev/subagents-and-workflows.md.bak` — мусорный файл в дереве доков (кандидат на удаление
  при слиянии).
