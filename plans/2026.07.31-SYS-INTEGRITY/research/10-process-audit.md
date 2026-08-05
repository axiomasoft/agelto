# research/10 — Аудит процессов пайплайна на изолируемость и тестируемость (P7.6)

**Плановый адрес:** `plans/2026.07.31-SYS-INTEGRITY/research/10-process-audit.md` (item P7.6).
**Коллизия номера:** в этом же каталоге лежит `research/10-p4-remediation.md` (ремедиация P4,
D123) — номер `10` делят два документа с разными слагами, отмечено в `index.md` и в Open risks
`handoff.md`. Переименование — вне Scope P7.6.

**Якорь дерева для всех числовых утверждений о коде:** `fec238a` (`git rev-parse --short HEAD`
на момент аудита; рабочее дерево несёт правки только файлов плана и чужой незакоммиченный дифф —
`app/`, `config/`, `routes/`, `tests/` не изменены). Числа о боевой БД сняты **2026-08-05** с
живого контейнера `agelto_postgres` (база `agelto`) и с контейнера `agelto_redis` — каждое
приведено вместе с командой.

**Что этот документ НЕ делает:** ничего не чинит (Implementation Rule 8), не предлагает
рефакторинг на модули или переезд на `nwidart/laravel-modules` (D14), не расширяет объём P7.7
сверх того, что утвердит владелец (D59).

---

## 1. Критерий изолируемости — явный перечень условий

Процесс считается **изолированно тестируемым**, когда выполняются ВСЕ пять условий. Условия
проверяемы по коду, а не «на глаз»; каждый вердикт §2 вынесен против этого списка.

| № | Условие | Как проверяется |
|:--|:--|:--|
| **И1** | **Ни сети, ни реального LLM.** Процесс запускается без обращения к внешним хостам; любой LLM-вызов идёт через `Tests\Support\Doubles\FakeLlmProvider`, HTTP — через `Http::fake()`/подменённый транспорт | правило проекта `.claude/rules/agelto-reviewer-overrides.md` (реальный LLM-вызов в тесте — блокирующее замечание); `grep -rlE "Http::(get\|post)\(\|curl_\|file_get_contents\('https?" tests/` → **0 файлов** (на `fec238a`) |
| **И2** | **Вход детерминирован.** Состояние задаётся фабриками/фикстурами, а не наличными данными боевой БД и не порядком других тестов | `tests/Pest.php`: `RefreshDatabase` на всём `Feature`; фабрики `X::factory()` (канон `.ai/guidelines/glossary.md`) |
| **И3** | **Результат наблюдаем.** Есть что утверждать: строка/статус в БД, вывод команды, отправленное уведомление, запушенный job — а не «не упало» | наличие `expect(...)`/`assertDatabaseHas`/`Queue::assertPushed`/`->expectsOutput` в тесте процесса |
| **И4** | **Состояние откатывается.** Прогон не оставляет следов: изолированная БД `agelto_test` (гейт `Tests\Support\TestEnvironmentGuard`), изолированный диск, `RefreshDatabase` | `tests/TestCase.php::createApplication()` → `TestEnvironmentGuard::assertIsolatedTestDatabase()` |
| **И5** | **Есть ПРЯМОЙ тест.** Тест вызывает САМ процесс: команду — через `artisan('<сигнатура>')`, job — через `new Job(...)->handle()`/`dispatchSync`. Косвенное упоминание класса в тесте соседнего сервиса прямым покрытием НЕ считается | грепы §2.1/§2.2, приведены поимённо |

**Три вердикта** (Implementation Rule 3 — «не покрыто» и «структурно не изолируемо» это РАЗНЫЕ
находки с разной ценой, смешение запрещено):

- **`изолированно тестируется`** — И1–И5 выполнены, тест назван файлом и именем.
- **`не покрыто`** — И1–И4 выполняются структурно (ничто в коде не мешает написать тест), но И5
  не выполнено. Цена — один тест.
- **`структурно не изолируемо`** — И1–И4 недостижимы без правки кода/харнесса. Цена — правка
  контура.

**Отдельная ось — наблюдаемость отказа** (§4). Она НЕ входит в критерий изолируемости: процесс
может быть безупречно покрыт тестами и при этом падать в проде незаметно. Смешивать эти оси
запрещено — «покрыт» ≠ «виден».

---

## 2. Таблица процессов

Инвентарь снят на `fec238a` (команды-счётчики — §5).

### 2.1 Job'ы — 5

`find app/Jobs -name '*.php' | wc -l` → **5** (на `fec238a`), совпадает с ожидаемым спеки P7.6
(«ни один item плана job'ов не заводит»).

| Процесс | Тип | Очередь | Уникальность | Вердикт | Чего не хватает | Ссылка на тест | Класс |
|:--|:--|:--|:--|:--|:--|:--|:--|
| `PipelineJob` (abstract) | job (шаблонный метод) | наследуется | `uniqueFor()` = 60 мин | **изолированно тестируется** | — | `tests/Feature/Procurement/Pipeline/LeaseTest.php` :: «PipelineJob: провальный status-guard не захватывает лиз и не выполняет process» + ещё 3 теста через анонимного наследника | — |
| `PrepareDocumentJob` | job | `doc_prepare` | `ShouldBeUnique` | **изолированно тестируется** | очередная семантика (release/backoff/конкурентные воркеры) не покрыта: тесты зовут `handle()` напрямую (`.claude/rules/tests.md`) | `tests/Feature/Procurement/Prepare/DocumentPreparerTest.php:50` `(new PrepareDocumentJob(...))->handle()`; unique-лок — `tests/Feature/Document/PipelineDispatcherTest.php` :: «unique-лок PrepareDocumentJob протухает по истечении uniqueFor — следующий dispatch пушит job (D25)» | P7.7 (частично, Д-2) |
| `ScreeningJob` | job | `ai_screening` | `ShouldBeUnique` | **изолированно тестируется** | то же + нет теста на САМ контракт уникальности этого job'а (подмена интерфейса не краснеет) | `tests/Feature/Procurement/Screening/ScreeningBranchingTest.php:110`, `tests/Feature/Ai/UsageSnapshotTest.php:237` | P7.7 (Д-2) |
| `EstimateExtractionJob` | job | `ai_extraction` | `ShouldBeUniqueUntilProcessing` (D39) | **изолированно тестируется** | сторож D39: подмена `ShouldBeUniqueUntilProcessing` → `ShouldBeUnique` НЕ краснеет ни одним тестом, хотя правило проекта объявляет её блокирующей | `tests/Feature/Procurement/Analyzers/Estimate/ExtractionOrchestratorTest.php:197`; self-dispatch продолжения — там же `:257` `Queue::assertPushed(EstimateExtractionJob::class, 1)` | P7.7 (Д-2) |
| `ShadowAnalysisJob` | job | `ai_shadow` | только `ShouldQueue` | **изолированно тестируется** | очередь `ai_shadow` не обслуживается ни одним supervisor'ом — job тестируем, но неисполняем (Д-1) | `tests/Feature/Llm/ShadowDispatchTest.php:103/146/182` (3 прямых прогона через `runShadowJob()`) + `:60` `Queue::assertPushedOn('ai_shadow', …)` | P7.7 (Д-1) |

**Итог по job'ам: 5/5 изолированно тестируются.** Утверждение разведки §13.6 «0 тестов в
`tests/**/Jobs`» верно буквально (такого каталога нет) и **неверно как утверждение о покрытии**:
все пять job'ов имеют прямые тесты, разложенные по доменным каталогам согласно канону
`tests/{Feature|Unit}/<Домен>/<Subject>Test.php`. Подробнее — Д-3.

### 2.2 Команды — 31

`find app/Console/Commands -name '*.php' | wc -l` → **31** (на `fec238a`). Спека P7.6 ожидала
**30** (29 на дизайне + `storage:disk-backfill` от P6.5). **Расхождение +1 названо, а не
подогнано** (Validation, D77): лишняя команда —
`app/Console/Commands/Procurement/RunDeltaScan.php` (`crawler:run-delta`), заведённая
**P4.6** (`git log --diff-filter=A` → `d297d44 fix(crawler): перекрытие окна рескана, delta-scan,
метрика отставания и дедуп блока ЕИС (P4.6)`). Спека P7.6 писалась до реопена P4 под D123 и
этого прироста не предвидела. Фактический список — ниже целиком.

Прямое покрытие определялось грепом инвокации, а не упоминанием строки:
`grep -rE "(artisan\(|Artisan::call\(|->call\()\s*'<сигнатура>'" tests/ --include='*.php'`.

| Процесс (сигнатура) | Файл | Вердикт | Ссылка на тест (файл :: имя) | Класс |
|:--|:--|:--|:--|:--|
| `partitions:maintain` | `MaintainPartitions.php` | изолированно тестируется | `tests/Feature/Console/MaintainPartitionsTest.php`, `tests/Feature/Infra/PartitionRetentionTest.php` :: «fallback-путь дропает партиции старше retention-окна, когда pg_partman…» | — |
| `ai:caches` | `Ai/CachesCommand.php` | изолированно тестируется | `tests/Feature/Ai/CachesTest.php` :: «list без активных explicit-кэшей — сообщение без падения» | — |
| `ai:cost-reconcile` | `Ai/CostReconcileCommand.php` | изолированно тестируется | `tests/Feature/Ai/CostReconcileTest.php` :: «дельта НИЖЕ порога — превышение не объявлено» | — |
| `ai:cost-report` | `Ai/CostReportCommand.php` | изолированно тестируется | `tests/Feature/Ai/CostReportTest.php` :: «отчёт группирует расход по (provider, model, purpose) и считает итого» | — |
| `ai:prompts:apply` | `Ai/PromptsApplyCommand.php` | изолированно тестируется | `tests/Feature/Ai/PromptApplyTest.php` :: «apply на pending отказывается и не трогает файл» | — |
| `ai:prompts:sync` | `Ai/PromptsSyncCommand.php` | изолированно тестируется | `tests/Feature/Ai/PromptsSyncTest.php`, `PromptCompositionTest.php`, `PromptApplyTest.php` (17 инвокаций) | — |
| `ai:storage:lifecycle` | `Ai/StorageLifecycleCommand.php` | изолированно тестируется | `tests/Feature/Infra/StorageLifecycleTest.php` :: «ai:storage:lifecycle применяет ILM-правило на пустом бакете» | — |
| `ai:tools:doctor` | `Ai/ToolsDoctorCommand.php` | изолированно тестируется | `tests/Feature/Ai/ToolsDoctorTest.php` :: «doctor зелёный, когда все бинари на месте» | — |
| `estimate:bench:formats` | `…/BenchFormatsCommand.php` | изолированно тестируется | `tests/Feature/Ai/BenchFormatsTest.php` :: «artisan(estimate:bench:formats) без --out пишет артефакт…» | — |
| `estimate:dataset:export` | `…/DatasetExportCommand.php` | изолированно тестируется | `tests/Feature/Ai/DatasetExportTest.php` :: «SFT-строка несёт ТЕКСТ представления, а не ссылку на документ» (11 инвокаций) | — |
| `estimate:dataset:import` | `…/DatasetImportCommand.php` | изолированно тестируется | `tests/Feature/Ai/DatasetImportTest.php` :: «импорт валидной пары создаёт Document/DocumentVersion вне закупки…» | — |
| `estimate:eval:compare` | `…/EvalCompareCommand.php` | изолированно тестируется | `tests/Feature/Ai/EvalCompareTest.php` :: «artisan estimate:eval:compare отдаёт вердикт с exit 0…» | — |
| `estimate:eval:diff` | `…/EvalDiffCommand.php` | изолированно тестируется | `tests/Feature/Ai/EvalDiffTest.php` :: «artisan(estimate:eval:diff) по --code печатает JSON-отчёт и вердикт» | — |
| `estimate:eval:run` | `…/EvalRunCommand.php` | изолированно тестируется | `tests/Feature/Ai/EvalRunTest.php`, `ScreeningEvalTest.php` :: «негатив, правильно классифицированный → accuracy 1.0 и FP 0» (16 инвокаций) | — |
| `estimate:fixtures:import` | `…/FixturesImportCommand.php` | изолированно тестируется | `tests/Feature/Ai/FixtureImportTest.php` :: «валидный манифест импортирует файл и запись реестра» | — |
| `estimate:fixtures:manifest` | `…/FixturesManifestCommand.php` | изолированно тестируется | `tests/Feature/Ai/FixtureManifestTest.php` :: «генератор строит валидный манифест из шорт-листа» | — |
| `estimate:fixtures:verify` | `…/FixturesVerifyCommand.php` | изолированно тестируется | `tests/Feature/Ai/FixtureVerifyTest.php` :: «verify зелёный на согласованном комплекте» | — |
| `estimate:gold:build` | `…/GoldBuildCommand.php` | изолированно тестируется | `tests/Feature/Ai/GoldBuildTest.php` :: «gsfx-эталон → файл gold на месте и валиден»; `ScreeningGoldTest.php` | — |
| `estimate:gold:import` | `…/GoldImportCommand.php` | изолированно тестируется | `tests/Feature/Ai/GoldImportTest.php` :: «эталон с extraction-gold → документ, версия и запись task=extraction…» (+2 файла) | — |
| `estimate:prompts:suggest` | `…/PromptsSuggestCommand.php` | изолированно тестируется | `tests/Feature/Ai/PromptSuggestTest.php` :: «--dry-run показывает вход критика и не делает ни одного вызова» | — |
| `procurement:convert-xlsx-to-pdf` | `Procurement/ConvertXlsxToPdfCommand.php` | **не покрыто** | — (0 инвокаций в `tests/`) | отдельный план |
| `crawler:fetch-details` | `Procurement/FetchDetails.php` | изолированно тестируется | `tests/Feature/Console/FetchDetailsTest.php` :: «снимает детальный слой по явному id закупки» | — |
| `pipeline:retry-failed` | `Procurement/PipelineRetryFailed.php` | **изолированно тестируется частично** | `tests/Feature/Procurement/Pipeline/PipelineRetryFailedTest.php` (4 теста) — покрыты документы и screening; **ветка extraction не покрыта ни одним тестом** (Д-4) | P7.7 (Д-4) |
| `pipeline:status` | `Procurement/PipelineStatus.php` | изолированно тестируется | `tests/Feature/Procurement/Pipeline/StuckCheckTest.php` :: «pipeline:status отрабатывает без ошибок и показывает счётчики документов…» | — |
| `pipeline:stuck-check` | `Procurement/PipelineStuckCheck.php` | **изолированно тестируется частично** | `tests/Feature/Procurement/Pipeline/StuckCheckTest.php` (16 инвокаций) :: «без аномалий команда завершается тихо, без Telegram-алерта» — но проверка `stuckDocuments()` слепа к стадии скачивания (Д-5) | P7.7 (Д-5) |
| `crawler:rescan-backlog` | `Procurement/RescanBacklog.php` | изолированно тестируется | `tests/Feature/Console/RescanBacklogTest.php` :: «делегирует BacklogRescanProcessor и репортит число обработанных прогонов» | — |
| `crawler:run-archive` | `Procurement/RunArchiveCrawler.php` | изолированно тестируется | `tests/Feature/Console/RunArchiveCrawlerTest.php` :: «резолвит профиль по числовому id и делегирует ArchiveRunProcessor…» | — |
| `crawler:run-delta` | `Procurement/RunDeltaScan.php` | **не покрыто** | — (0 инвокаций в `tests/`) | отдельный план |
| `crawler:run-fresh` | `Procurement/RunFreshCrawler.php` | изолированно тестируется | `tests/Feature/Console/RunFreshCrawlerTest.php` :: «резолвит профиль по числовому id и делегирует FreshRunProcessor» | — |
| `ref:load-classifiers` | `Reference/LoadClassifiers.php` | изолированно тестируется | `tests/Feature/Reference/LoadClassifiersTest.php` :: «ref:load-classifiers наполняет ref_okpd2 из выгрузки» | — |
| `storage:disk-backfill` | `Storage/DiskBackfillCommand.php` | изолированно тестируется | `tests/Feature/Storage/DiskBackfillTest.php` :: «перенос копирует объект потоком и обновляет диск записи после совпадения…» | — |

**Итог по командам: 29/31 имеют прямой тест, 2 не покрыты.** Обе непокрытые —
структурно изолируемы (И1–И4 выполняются): `procurement:convert-xlsx-to-pdf` — чистое
файл-в-файл преобразование через PhpSpreadsheet/Mpdf, без сети и БД;
`crawler:run-delta` — та же форма, что покрытые `crawler:run-fresh`/`crawler:run-archive`
(резолв профиля + делегирование процессору). То есть это **«не покрыто»**, а не
«структурно не изолируемо»: цена каждой — один тест.

### 2.3 Расписание — 8 записей

Источник — `routes/console.php` (на `fec238a`). Тестов расписания **нет ни одного**:
`grep -rn "Schedule::\|schedule:list\|schedule:run" tests/ --include='*.php'` → **0 совпадений**.

| Запись | Периодичность | Защита от наложения | Вердикт | Класс |
|:--|:--|:--|:--|:--|
| `partitions:maintain` | `daily()` | нет | не покрыто (сама запись; команда покрыта) | отдельный план |
| `crawler:run-fresh <id>` | `cron($crawler->schedule ?? '*/30 * * * *')` на каждый активный fresh-профиль | **нет** | не покрыто; наложение не исключено (Д-10) | отдельный план (Д-10) |
| `crawler:fetch-details` | `everyFiveMinutes()` | **нет** | не покрыто; наложение не исключено и не обезврежено claim'ом (Д-10) | отдельный план (Д-10) |
| `crawler:rescan-backlog` | `everyFifteenMinutes()` | **нет** | не покрыто (Д-10) | отдельный план (Д-10) |
| `crawler:run-delta` | `cron(config('crawler.tracking.delta_scan_cron'))`, за гейтом `delta_scan_enabled` (выключен) | **нет** | не покрыто (Д-10) | отдельный план (Д-10) |
| `pipeline:dispatch-backlog` (замыкание `Schedule::call`) | `everyMinute()` | `withoutOverlapping()` | не покрыто как запись; `PipelineDispatcher::dispatchBacklog()` покрыт `tests/Feature/Document/PipelineDispatcherTest.php` | отдельный план |
| `pipeline:retry-failed` | `everyTenMinutes()` | `withoutOverlapping()` | не покрыто как запись; команда покрыта частично (Д-4) | P7.7 (через Д-4) |
| `pipeline:stuck-check` | `everyFifteenMinutes()` | `withoutOverlapping()` | не покрыто как запись; команда покрыта частично (Д-5) | P7.7 (через Д-5) |

**Отдельно (Д-11):** `routes/console.php` выполняет `Schema::hasTable()` ×4 и выборку
`prc_crawlers` на КАЖДЫЙ artisan-вызов, не только на `schedule:run` — это цена в БД-запросах у
любой консольной команды, включая тестовые прогоны. Schema-guard'ы прокомментированы и
осмысленны (файл читается до первой миграции), но выборка профилей под ними — нет.

**Не запланировано ничего из штатной эксплуатационной обвязки** (Д-6/Д-7/Д-8):
`grep -rn "horizon:snapshot\|pulse:check\|pulse:trim\|queue:prune" routes/ app/` → **0
совпадений** (на `fec238a`). Ни Horizon, ни Pulse эти задачи сами не регистрируют — проверено
по `vendor/laravel/horizon/src/HorizonServiceProvider.php` и
`vendor/laravel/pulse/src/PulseServiceProvider.php` (совпадений `schedule` — 0).

### 2.4 Supervisor'ы Horizon — 4 (+1 осиротевшая очередь)

`grep -oE "'supervisor-[a-z0-9-]+'" config/horizon.php | sort -u | wc -l` → **4** (на `fec238a`),
совпадает с ожидаемым спеки. Наивный `grep -c "supervisor-"` даёт 12 — те же четыре в трёх блоках
(`defaults`, `environments.production`, `environments.local`).

| Supervisor | Очередь | `maxProcesses` (default/prod/local) | `memory` | `tries` | `timeout` | Вердикт |
|:--|:--|:--|:--|:--|:--|:--|
| `supervisor-1` | `default` | 1/10/3 | 128 | 1 | 60 | инварианты покрыты `tests/Unit/Config/QueueTimeoutInvariantTest.php` |
| `supervisor-doc-prepare` | `doc_prepare` | 3/6/2 | 128 | 1 | 120 | то же |
| `supervisor-ai-screening` | `ai_screening` | 2/3/1 | 128 | 1 | 60 | то же |
| `supervisor-ai-extraction` | `ai_extraction` | 2/3/1 | 256 | 1 | 300 | то же; задаёт потолок инварианта D26 |
| **— (отсутствует)** | **`ai_shadow`** | — | — | — | — | **дефект Д-1: очередь объявлена job'ом, supervisor'а нет** |

Инвариант D26 (`timeout < retry_after < stop_grace_period`) покрыт тремя тестами
`tests/Unit/Config/QueueTimeoutInvariantTest.php` — но **только первые два звена читаются из
PHP-рантайма**; третье (`stop_grace_period`) зафиксировано в тесте КОНСТАНТОЙ `600`, что сам тест
честно называет комментарием. Фактический dev-компоуз третьего звена не несёт вовсе (Д-9).

### 2.5 Стадии пайплайна — 4

| Стадия | Носитель | Переход | Вердикт | Ссылка |
|:--|:--|:--|:--|:--|
| download | `DetailAnalysisProcessor::downloadPendingDocuments()` + `DocumentDownloader` — **синхронно внутри команды `crawler:fetch-details`, не job** | `discovered → downloaded` | изолированно тестируется (через команду) | `tests/Feature/Console/FetchDetailsTest.php` |
| prepare | `PrepareDocumentJob` → `DocumentPreparer` | `downloaded → prepared`/`unprocessable` | изолированно тестируется | `tests/Feature/Procurement/Prepare/DocumentPreparerTest.php` |
| screening | `ScreeningJob` → `ScreeningProcessor` | `prepared → screened` | изолированно тестируется | `tests/Feature/Procurement/Screening/ScreeningProcessorTest.php`, `ScreeningBranchingTest.php` |
| extraction | `EstimateExtractionJob` → `ExtractionOrchestrator` (checkpoint-requeue) | `screened → extracted`/`failed`/`unprocessable` | изолированно тестируется | `tests/Feature/Procurement/Analyzers/Estimate/ExtractionOrchestratorTest.php`, `WindowedExtractionTest.php` |

**Асимметрия стадий, названная как факт:** три из четырёх стадий — очередные job'ы с
status-guard'ом, лизом и error-блоком; **скачивание единственное живёт синхронно внутри
расписанной команды**, без лиза, без своей очереди и без своего supervisor'а. Отсюда растут
Д-5 (стадия невидима для `pipeline:stuck-check`) и Д-10 (нет claim'а против наложения тиков).
Перенос скачивания в job — **не предложение этого аудита** (кардинальная правка контура, D14/D59);
факт зафиксирован как основание отдельного плана.

---

## 3. Дефекты контракта процесса

Три дефекта, названные разведкой (`findings/repo-recon-2026-07-31.md` §13.5–§13.6), получают
явный вердикт КАЖДЫЙ (Implementation Rule 6) — включая тот, который аудит **опровергает**.
Дальше — найденные сверх.

### Д-1 — очередь `ai_shadow` без supervisor'а · **ПОДТВЕРЖДЁН**

`ShadowAnalysisJob::__construct()` вызывает `$this->onQueue('ai_shadow')`
(`app/Jobs/Procurement/ShadowAnalysisJob.php:74`), а `config/horizon.php` объявляет только
`default`, `doc_prepare`, `ai_screening`, `ai_extraction` (§2.4). Теневые прогоны попадают в Redis
и не разбираются никем, пока вручную не поднят `queue:work ai_shadow`.

**Текущее состояние — латентное, не сработавшее:**
`docker exec agelto_redis redis-cli --scan --pattern "*queue*"` → `agelto_horizon:queue:doc_prepare`,
`agelto_horizon:measured_queues` (2026-08-05). Ключа `ai_shadow` НЕТ: теневых маршрутов
(`llm_routes.shadow = true`) на боевой базе пока не заведено, поэтому job ни разу не
диспатчился. Дефект не «уже накопил задачи», а **выстрелит в момент включения shadow-контура** —
и выстрелит молча (job не падает, он просто никогда не выполняется).

**Класс: P7.7** — прямо назван в Scope Included P7.7.

### Д-2 — ни один job не объявляет `$tries`/`$backoff`/`$timeout` при `tries: 1` · **ПОДТВЕРЖДЁН, с уточнением диагноза**

`grep -rnE '\$tries|\$backoff|\$timeout|retryUntil|failOnTimeout|\$maxExceptions' app/Jobs/` →
**0 совпадений** (на `fec238a`); во всех четырёх supervisor'ах `tries: 1`.

**Уточнение:** само по себе это НЕ дефект, а сознательная конструкция — ретрай вынесен из
очереди в доменный контур: `HasErrorBlock` (`attempts`/`next_retry_at`/`failed_permanently`,
экспоненциальный backoff `min(2**attempts, ai.retry.max_backoff_minutes)`) + расписанный
`pipeline:retry-failed`. Плюс конструкции: ретрай переживает рестарт воркера, виден в БД,
управляется доменными порогами. Дефект — в трёх вещах вокруг неё:

1. **Решение нигде не записано как решение.** Ни D-записи, ни комментария в `config/horizon.php`
   рядом с `tries: 1`, ни в `docs/03_Dev/document-pipeline.md`. Следующий разработчик прочитает
   `tries: 1` как недосмотр и «починит» — сломав контур (задача пойдёт в ретрай дважды: очередью
   и командой).
2. **Сторожа нет.** Ни один тест не краснеет, если у job'а появится `$tries = 3` или если
   `EstimateExtractionJob` подменят `ShouldBeUnique` вместо `ShouldBeUniqueUntilProcessing` —
   при том, что вторую подмену правило проекта (`.claude/rules/agelto-reviewer-overrides.md`)
   объявляет **блокирующей**, а D39 называет её причиной вечного зависания документа.
3. **Контур неполон** — см. Д-4 (extraction из него выпал) и Д-8 (`failed_jobs` — второй,
   немой носитель отказа рядом с error-блоком).

**Класс: P7.7** — прямо назван в Scope Included P7.7 («явные `$tries`/`$backoff`/`$timeout` там,
где аудит показал их необходимость, **или D-запись, почему их сознательно нет**»). Аудит
показывает: нужна D-запись + сторож, а не свойства.

### Д-3 — «непокрытые job'ы и команды: 0 тестов в `tests/**/Jobs`, 5 команд из 29» · **ОПРОВЕРГНУТ В ИСХОДНОЙ ФОРМУЛИРОВКЕ**

Исходное утверждение разведки измеряло **каталоги**, а не поведение:
`find tests -ipath '*Jobs*' -name '*Test.php'` → 0 и `find tests -ipath '*Command*'` → 0 верны
буквально (таких каталогов нет — тесты разложены по ДОМЕНАМ согласно канону
`tests/{Feature|Unit}/<Домен>/<Subject>Test.php`), а «5 из 29» считало только содержимое
`tests/Feature/Console/`.

**Замер по инвокации, а не по каталогу** (§2.1–§2.2, на `fec238a`):

- job'ы: **5 из 5** имеют прямой прогон (`new Job(...)->handle()`);
- команды: **29 из 31** имеют прямую инвокацию (`artisan('<сигнатура>')`).

**Реальный остаток дефекта — три пункта, а не один:**

1. две команды без прямого теста: `procurement:convert-xlsx-to-pdf`, `crawler:run-delta`;
2. **очередная семантика не покрыта нигде**: все тесты job'ов зовут `handle()` напрямую, минуя
   очередь — release/backoff/поведение при конкурентных воркерах не проверяются
   (`.claude/rules/tests.md` требует это отмечать явно). Частичное исключение — unique-лок
   `PrepareDocumentJob` (`PipelineDispatcherTest`, 3 теста);
3. **расписание не покрыто ни одним тестом** (§2.3).

**Класс:** пункт 2 в части сторожа D39 → **P7.7**; пункты 1 и 3 и остальная очередная семантика →
**отдельный план** (D59: сплошное покрытие — основание отдельного плана; сомнение — в пользу
отдельного плана).

### Д-4 — `pipeline:retry-failed` НИКОГДА не ретраит extraction · **НОВЫЙ, тяжелейший из найденных**

`app/Console/Commands/Procurement/PipelineRetryFailed.php::dispatchAnalysisRetry()`:

```php
PipelineStage::Extraction => throw new LogicException(
    "Анализ #{$analysis->id}: стадия extraction ещё не реализована (P6) — ретрай отложен.",
),
```

`EstimateExtractionJob` существует с коммита `e06434c` (`refactor(procurement): расщепление слоя
Extraction — машинерия ↔ предметка сметы (P2.4)`), а `PipelineRetryFailed.php` с тех пор менялся
только переносами namespace'ов (`git log --oneline -- app/Console/Commands/Procurement/PipelineRetryFailed.php`
→ `4e844fe` P3.3, `c25bf2e` P3.2, `4d08875` P1.6, `5847c27` — заведение). Ветка мертва и лжёт.

**Механика отказа — молчаливая и самоподдерживающаяся.** На каждом тике (`everyTenMinutes()`):
атомарный claim уже **сдвинул `next_retry_at` на +10 минут**, и только потом
`dispatchAnalysisRetry()` бросает `LogicException`, который ловится в `retryAnalyses()` и
печатается как `$this->warn(...)`. Итог: провалившийся extraction-анализ вечно висит в
`status = failed, attempts > 0, failed_permanently = false`, каждые 10 минут его claim'ит
и отпускает команда, `attempts` не растёт (job не диспатчится → `recordFailure()` не вызывается),
до `failed_permanently` он не дойдёт НИКОГДА, а в консоль уходит warning, который в проде
никто не читает.

**Тестом не поймано:** в `tests/Feature/Procurement/Pipeline/PipelineRetryFailedTest.php`
`grep -n "extraction\|Extraction\|LogicException"` → **0 совпадений** — четыре теста покрывают
документы и screening.

**На боевой БД пока не сработало:** `select step, status, count(*) from prc_ai_analyses group by 1,2`
→ **0 строк** (2026-08-05) — extraction на боевых данных ещё не запускался (AI-стадии за
выключенными гейтами). То есть дефект **латентный и выстрелит на первом же провале извлечения**.

**Класс: P7.7** — «контур `pipeline:retry-failed`» прямо назван в Scope Included P7.7.

### Д-5 — `pipeline:stuck-check` слеп к стадии скачивания · **НОВЫЙ**

`PipelineStuckCheck::stuckDocuments()` смотрит
`whereIn('status', [Downloaded, Prepared, Screened])` — статуса `Discovered` в списке нет.
Документ, у которого упало СКАЧИВАНИЕ, не попадает ни в одну проверку команды.

**Факт на боевой БД (2026-08-05):**

```
docker exec agelto_postgres psql -U agelto -d agelto -c "select status, count(*) filter (where attempts>0) as with_attempts, count(*) as total from prc_documents group by status"
   discovered  | with_attempts = 4  | total = 4
   downloaded  | with_attempts = 19 | total = 19
```

**Четыре документа с зафиксированным провалом скачивания невидимы для наблюдаемости прямо
сейчас.** Ретрай для них формально есть (`DetailAnalysisProcessor::downloadPendingDocuments()`
фильтрует `->pendingRetry()`), но он срабатывает, только когда РОДИТЕЛЬСКАЯ закупка снова
станет due по `DetailRefetchSelector` — то есть ретрай документа привязан к расписанию
перечитывания карточки закупки, а не к своему `next_retry_at`. Это связывание нигде не
задокументировано.

**Класс: P7.7** в части наблюдаемости (добавить `Discovered` в `stuckDocuments()` + тест) —
это правка одного read-only метода. Развязывание ретрая скачивания от refetch-расписания —
**отдельный план** (правка боевого контура скачивания, не «дефект контракта»).

### Д-6 — `horizon:snapshot` не расписан · **НОВЫЙ**

`grep -rn "horizon:snapshot" routes/ app/` → **0** (на `fec238a`); Horizon сам эту задачу не
регистрирует (проверено по `vendor/laravel/horizon/src/HorizonServiceProvider.php`). Следствие:
`config/horizon.php` заводит `metrics.trim_snapshots` (job 24, queue 24), но снапшоты не
снимаются — **графики метрик Horizon пусты навсегда**. Основная поверхность ответа на вопрос
«очередь растёт / job'ы стали дольше» не работает.

**Класс: гейт владельца** (см. §6) — правка на одну строку расписания, но формально это НОВЫЙ
процесс, а не фикс дефекта контракта; по Rule 7 «сомнение — в пользу отдельного плана».

### Д-7 — `pulse:check`/`pulse:trim` не расписаны, таблицы Pulse растут без пруна · **НОВЫЙ**

`grep -rn "pulse:check\|pulse:trim" routes/ app/` → **0**; Pulse сам не регистрирует
(`vendor/laravel/pulse/src/PulseServiceProvider.php` — совпадений `schedule` 0).
`config/pulse.php`: `'enabled' => env('PULSE_ENABLED', true)`, включены рекордеры
`queues`, `slow_jobs`, `exceptions`, `cache_interactions`.

**Факт на боевой БД (2026-08-05):**

```
docker exec agelto_postgres psql -U agelto -d agelto -c "select relname, n_live_tup, pg_size_pretty(pg_total_relation_size(relid)) from pg_stat_user_tables where relname like 'pulse%'"
   pulse_aggregates | 4198 | 2952 kB
   pulse_entries    |  601 |  496 kB
   pulse_values     |    0 |   40 kB
```

Рекордеры пишут (данные есть), а прун не запускается ни разу — рост неограничен. Это же
касается ревизии схемы P7: три таблицы без retention на той самой БД, чью топологию
подтверждал P7.3. Карточки, которым нужен поллинг (`servers`), пусты.

**Класс: гейт владельца** (та же логика, что Д-6).

### Д-8 — `failed_jobs` — второй, немой носитель отказа · **НОВЫЙ**

`config/queue.php`: `'failed' => ['driver' => 'database-uuids', 'table' => 'failed_jobs']`.
`queue:prune-failed` не расписан (`grep -rn "queue:prune" routes/ app/` → 0).

**Факт на боевой БД (2026-08-05):**

```
docker exec agelto_postgres psql -U agelto -d agelto -c "select substring(payload::json->>'displayName' from 1 for 60) as job, count(*), max(failed_at) from failed_jobs group by 1"
   App\Jobs\Procurement\PrepareDocumentJob | 19 | 2026-08-05 13:48:27
```

Девятнадцать провалов `PrepareDocumentJob` (те же 19 документов, что в §Д-5 стоят
`downloaded, attempts > 0`). То есть один и тот же отказ записан ДВАЖДЫ: в error-блок документа
(его читает `pipeline:retry-failed`) и в `failed_jobs` (его не читает никто и не чистит ничто).
Ни `pipeline:stuck-check`, ни `pipeline:status` в `failed_jobs` не заглядывают.

**Класс: гейт владельца** — либо прун + строка в `pipeline:stuck-check`, либо явное решение
«`failed_jobs` — только для ручного разбора в Horizon UI» с D-записью.

### Д-9 — третье звено инварианта D26 отсутствует в dev-компоузе · **НОВЫЙ**

`docker-compose.prod.yml:80` — `stop_grace_period: ${HORIZON_STOP_GRACE_PERIOD:-600s}` у сервиса
`horizon`. В `docker-compose.yml` (dev) сервис `horizon` (строки 67–93) `stop_grace_period` **не
объявляет вовсе** — действует дефолт Docker, 10 секунд, при `supervisor-ai-extraction.timeout =
300`. Соседний сервис `scheduler` в том же файле grace-период объявляет (`60s`), то есть это
пропуск, а не осознанная асимметрия.

Следствие: `docker compose restart horizon` в dev SIGKILL'ит воркер через 10 секунд, посреди
извлечения; лиз `Analysis` держится `HasLease::acquireLease($owner, $ttlSeconds = 600)` — 10
минут, unique-лок — `uniqueFor()` = 60 минут. Документ на час выпадает из обработки после
обычного рестарта.

`tests/Unit/Config/QueueTimeoutInvariantTest.php` этого не ловит: третий тест сверяется с
захардкоженной константой `600` и сам честно пишет, что `docker-compose.prod.yml` из PHP не
читается — про dev-компоуз речи нет вовсе.

**Класс: P7.7** — одна строка в `docker-compose.yml`; сторож (расширение существующего теста
или проверка обоих компоузов) — там же.

### Д-10 — четыре расписанных crawler-команды без защиты от наложения · **НОВЫЙ**

`crawler:run-fresh`, `crawler:fetch-details`, `crawler:rescan-backlog`, `crawler:run-delta`
расписаны БЕЗ `withoutOverlapping()` (§2.3), при том что все три `pipeline:*`-записи в том же
файле его несут. Внутреннего claim'а у них тоже нет:
`grep -nE "withoutOverlapping|Cache::lock|lockForUpdate" app/Console/Commands/Procurement/{FetchDetails,RunFreshCrawler,RescanBacklog,RunDeltaScan,RunArchiveCrawler}.php`
→ **0 совпадений**, а `DetailRefetchSelector::due()` — **чистое чтение** (`Procurement::query()
->where(...)->lazy()`, без UPDATE-claim'а, в отличие от `PipelineRetryFailed`, где claim атомарный).

Следствие: если тик `crawler:fetch-details` (каждые 5 минут) идёт дольше пяти минут, следующий
выбирает ТЕ ЖЕ закупки и повторно ходит за карточками в ЕИС через прокси — лишний трафик,
лишний расход прокси-пула и лишний риск по rate-limit площадки. То же для `crawler:run-fresh`:
два одновременных прогона одного профиля создадут два `CrawlerRun`.

**Класс: отдельный план.** Однострочником это не чинится честно: `withoutOverlapping()` на
`fetch-details` подавит тик целиком (включая закупки, которые обработать успели бы), а
атомарный claim требует колонки-маркера и решения о её семантике — это дизайн, а не фикс.
Rule 7: сомнение — в пользу отдельного плана.

### Д-11 — `routes/console.php` ходит в БД на каждый artisan-вызов · **НОВЫЙ, слабый**

Четыре `Schema::hasTable()` + выборка всех активных fresh-профилей (`Crawler::query()->…->get()`)
выполняются при КАЖДОМ вызове artisan, а не только при `schedule:run`. Schema-guard'ы
обоснованы комментарием (файл читается до первой миграции); выборка профилей под ними — нет.
Расписание при этом не покрыто ни одним тестом (§2.3), так что изменение этой цены никто не
заметит.

**Класс: отдельный план.**

### Д-12 — нет структурного гейта против сетевого вызова в тесте · **НОВЫЙ, слабый**

Условие И1 сегодня держится дисциплиной и ревью (`.claude/rules/agelto-reviewer-overrides.md`),
а не механикой: `grep -rn "preventStrayRequests" tests/ app/` → **0 совпадений**. Фактически
нарушений нет (`grep -rlE "Http::(get|post)\(|curl_|file_get_contents\('https?" tests/` → 0
файлов), то есть это профилактика, а не находка по факту.

**Класс: отдельный план** (Rule 7 — сомнение в пользу отдельного плана; правка трогает весь
тестовый харнесс и может вскрыть скрытые обращения).

---

## 4. Наблюдаемость отказа

Вопрос раздела: **когда процесс упал или завис — что и где видно.** Ось независима от
покрытия тестами (§1).

| Носитель | Что фиксирует | Кто читает | Ретенция | Пробел |
|:--|:--|:--|:--|:--|
| **Error-блок модели** (`HasErrorBlock`: `attempts`/`last_error`/`last_error_at`/`next_retry_at`/`failed_permanently` на `Document` и `Ai\Analysis`) | провал стадии + окно следующей попытки | `pipeline:retry-failed` (диспатчит), `pipeline:stuck-check` (сигналит), `pipeline:status` (счётчики), Filament | бессрочно (строка живёт с моделью) | **extraction из ретрая выпал (Д-4)**; **стадия скачивания из stuck-check выпала (Д-5)** |
| **`failed_jobs`** (`database-uuids`) | необработанное исключение job'а целиком, с payload | **никто** — ни команда, ни алерт; только Horizon UI вручную | **бессрочно, пруна нет** | **Д-8**: 19 записей, дубль того же отказа, что уже в error-блоке |
| **Horizon** (`/horizon`) | текущие/недавние/failed jobs, длина очередей, `waits` (порог `redis:default` = 60s), уведомления `LongWaitDetected` | оператор вручную | `trim.recent` 60 мин, `failed` 7 суток | очередь `ai_shadow` в Horizon **не существует** (Д-1) — рост её backlog'а не виден нигде; **метрики пусты (Д-6)** |
| **Pulse** (`/pulse`) | `queues`, `slow_jobs`, `exceptions`, `cache_interactions` + собственная карточка `app/Pulse/Cards/PipelineStagesCard.php` | оператор вручную | **пруна нет (Д-7)** | карточки поллинга (`servers`) пусты; таблицы растут |
| **Sentry** (`sentry/sentry-laravel`, `bootstrap/app.php` → `Integration::handles($exceptions)`) | необработанные исключения, в т.ч. из job'ов | внешняя система | внешняя | **fail-open: `SENTRY_LARAVEL_DSN` пуст → no-op** (комментарий в `bootstrap/app.php` называет это Pending Work владельца). Исключения `ShadowAnalysisJob` до Sentry не доходят ВООБЩЕ — job их глотает и пишет только `Log::warning` (сознательно, §докблок job'а) |
| **`pipeline:stuck-check` → `PipelineAlert`** (Telegram, антиспам 1/час на ключ находки) | 11 проверок: зависшие анализы (screening/extraction — разные пороги), незакрытые прогоны, зависшие документы, всплески `unknown`/`unprocessable`/расхождений смет, EOL-модели, backlog, доля отказов, отсутствие завершений | владелец, push | — | слеп к `Discovered` (Д-5) и к `failed_jobs` (Д-8) |
| **Логи** (`config/logging.php`, канал `json`) | `Log::warning` из `ShadowAnalysisJob`, `DetailAnalysisProcessor::markFailed()`, `warn()` из `pipeline:retry-failed` | оператор вручную (`grep`) | по драйверу | **предупреждение о нереализованном extraction-ретрае (Д-4) уходит именно сюда — то есть в тишину** |

**Три вывода раздела:**

1. **Единственный канал, который сам приходит к человеку, — `PipelineAlert` в Telegram**
   (всё остальное требует, чтобы кто-то открыл дашборд). Значит цена любой дыры в
   `pipeline:stuck-check` — не «менее удобно», а «не узнаем вовсе»; Д-5 читается именно так.
2. **Один отказ пишется в два носителя** (error-блок + `failed_jobs`), из которых читается
   один. Это не избыточность, а расхождение: `failed_jobs` копит, error-блок ретраится.
3. **Аварийный контур наблюдаемости сам не обслуживается**: снапшоты Horizon не снимаются
   (Д-6), таблицы Pulse не прунятся (Д-7), `failed_jobs` не прунится (Д-8). Три расписанные
   строки, которых нет.

---

## 5. Сводка

### 5.1 Числа и команды-счётчики (все — на `fec238a`)

| Что | Команда | Значение |
|:--|:--|:--|
| Job'ы | `find app/Jobs -name '*.php' \| wc -l` | **5** (ожидалось 5 ✓) |
| Команды | `find app/Console/Commands -name '*.php' \| wc -l` | **31** (ожидалось 30 — расхождение +1 названо, §2.2) |
| Supervisor'ы | `grep -oE "'supervisor-[a-z0-9-]+'" config/horizon.php \| sort -u \| wc -l` | **4** (ожидалось 4 ✓) |
| Записи расписания | `grep -cE "^\s*Schedule::(command\|call)\(" routes/console.php` | **8** |
| Тест-файлы всего | `find tests -name '*Test.php' \| wc -l` | **180** (было 158 на дизайне) |
| Job'ы с прямым тестом | по каждому: `grep -rlE "new <Job>\(" tests/ --include='*.php' \| wc -l` → `PrepareDocumentJob` 3, `ScreeningJob` 4, `EstimateExtractionJob` 4, `ShadowAnalysisJob` 1 файл; абстракт `PipelineJob` — через анонимного наследника в `tests/Feature/Procurement/Pipeline/LeaseTest.php` | **5 / 5 job'ов покрыты** (суммарно `grep -rlE "new (PipelineJob\|PrepareDocumentJob\|ScreeningJob\|EstimateExtractionJob\|ShadowAnalysisJob)\(" tests/ --include='*.php' \| wc -l` → 10 файлов) |
| Команды с прямой инвокацией | цикл по 31 сигнатуре: `grep -rE "(artisan\(\|Artisan::call\(\|->call\()\s*'<сиг>'" tests/ --include='*.php'` | **29 / 31** |
| Тесты расписания | `grep -rn "Schedule::\|schedule:list\|schedule:run" tests/ --include='*.php' \| wc -l` | **0** |
| Свойства ретрая в job'ах | `grep -rnE '\$tries\|\$backoff\|\$timeout\|retryUntil' app/Jobs/ \| wc -l` | **0** |
| Эксплуатационные задачи в расписании | `grep -rn "horizon:snapshot\|pulse:check\|pulse:trim\|queue:prune" routes/ app/ \| wc -l` | **0** |
| Сетевые вызовы в тестах | `grep -rlE "Http::(get\|post)\(\|curl_\|file_get_contents\('https?" tests/ --include='*.php' \| wc -l` | **0** |

### 5.2 Сколько процессов какого вердикта

| Тип процесса | Всего | изолированно тестируется | частично | не покрыто | структурно не изолируемо |
|:--|--:|--:|--:|--:|--:|
| Job'ы | 5 | 5 | — | 0 | 0 |
| Команды | 31 | 27 | 2 (`pipeline:retry-failed`, `pipeline:stuck-check` — покрыты, но с дырой в поведении) | 2 | 0 |
| Записи расписания | 8 | 0 | — | 8 | 0 |
| Supervisor'ы (конфиг-инварианты) | 4 | 4 (`QueueTimeoutInvariantTest`) | — | 0 | 0 |
| Стадии пайплайна | 4 | 4 | — | 0 | 0 |
| **Итого** | **52** | **40** | **2** | **10** | **0** |

**Ни один процесс не признан структурно неизолируемым.** Это главный положительный вывод
аудита и прямой ответ на п.6 брифа: контур уже устроен так, что каждый процесс МОЖЕТ быть
покрыт — status-guard/лиз/error-блок вынесены в переиспользуемые черты, LLM спрятан за
`Contracts\LlmProvider` с `FakeLlmProvider` в тестах, БД изолирована гейтом
`TestEnvironmentGuard`. Дыры — не в архитектуре процессов, а в трёх местах: (1) очередная
семантика проверяется только через `handle()` в обход очереди, (2) расписание не проверяется
вовсе, (3) эксплуатационная обвязка наблюдаемости не заведена.

### 5.3 Все находки с классом

| # | Находка | Тяжесть | Класс |
|:--|:--|:--|:--|
| Д-1 | очередь `ai_shadow` без supervisor'а (латентно — shadow-маршрутов пока нет) | высокая | **P7.7** |
| Д-2 | `tries: 1` + нет свойств ретрая: решение не записано, сторожа нет | средняя | **P7.7** (D-запись + сторож) |
| Д-3 | покрытие: 2 команды без теста, очередная семантика и расписание не покрыты | средняя | **P7.7** частично (сторож D39); остальное — отдельный план |
| Д-4 | `pipeline:retry-failed` бросает `LogicException` на extraction — стадия никогда не ретраится | **высокая** | **P7.7** |
| Д-5 | `pipeline:stuck-check` слеп к `Discovered` — 4 документа невидимы прямо сейчас | высокая | **P7.7** (наблюдаемость); развязка ретрая — отдельный план |
| Д-6 | `horizon:snapshot` не расписан — метрики Horizon пусты | средняя | **гейт владельца** |
| Д-7 | `pulse:check`/`pulse:trim` не расписаны — 3 таблицы Pulse без пруна | средняя | **гейт владельца** |
| Д-8 | `failed_jobs` (19 записей) никем не читается и не прунится | средняя | **гейт владельца** |
| Д-9 | dev-компоуз `horizon` без `stop_grace_period` при timeout 300s | средняя | **P7.7** |
| Д-10 | 4 расписанные crawler-команды без `withoutOverlapping()` и без claim'а | средняя | **отдельный план** |
| Д-11 | `routes/console.php` ходит в БД на каждый artisan-вызов | низкая | **отдельный план** |
| Д-12 | нет `Http::preventStrayRequests()` — И1 держится дисциплиной | низкая | **отдельный план** |

---

## 6. Перечень для гейта владельца (P7.7)

Список — **предложение исполнителя**, не самоутверждение (D59: объём фиксов ограничен решением
владельца; тот же приём, что P3.1/OQ-8). Провенанс — `open-questions.md` OQ-9.

### 6.1 Предлагается в P7.7 (5 позиций)

| # | Фикс | Обоснование попадания в P7.7 | Объём |
|:--|:--|:--|:--|
| 1 | **Д-4**: убрать мёртвую ветку `LogicException` — `PipelineStage::Extraction` диспатчит `EstimateExtractionJob`; тест на ретрай extraction | «контур `pipeline:retry-failed`» прямо назван в Scope Included P7.7; дефект контракта процесса | 1 файл + 1–2 теста |
| 2 | **Д-1**: решение по `ai_shadow` — supervisor в `config/horizon.php` с обоснованными `memory`/`timeout`/`maxProcesses` ЛИБО снятие очереди с job'а | прямо назван в Scope Included P7.7; «оставим как есть» запрещено Implementation Rule 2 P7.7 | 1 файл + тест инварианта |
| 3 | **Д-5**: добавить `DocumentStatus::Discovered` в `PipelineStuckCheck::stuckDocuments()`; тест | наблюдаемость единственного канала, который сам приходит к человеку; 4 документа невидимы уже сейчас | 1 метод + 1 тест |
| 4 | **Д-2 + Д-3(сторож)**: D-запись «ретрай сознательно вне очереди» + сторож контракта job'ов (тест краснеет на появление `$tries` и на подмену `ShouldBeUniqueUntilProcessing` → `ShouldBeUnique`) | прямо назван в Scope Included P7.7 («или D-запись, почему их сознательно нет»); D39 + правило проекта объявляют подмену блокирующей | 1 тест + D-запись + комментарий в конфиге |
| 5 | **Д-9**: `stop_grace_period` сервису `horizon` в `docker-compose.yml`; расширить `QueueTimeoutInvariantTest` на оба компоуза | третье звено уже задокументированного инварианта D26; сегодня существует только в prod | 1 строка + 1 тест |

### 6.2 Требует отдельного вердикта владельца (3 позиции)

Формально это НЕ «фиксы дефекта контракта процесса», а **заведение отсутствующих процессов**,
поэтому Rule 7 велит по умолчанию отправить их в отдельный план. Но все три — по одной строке
расписания, все три чинят наблюдаемость, и одна (Д-7) прямо касается предмета фазы P7 — роста
таблиц на той самой БД, чью топологию подтверждал P7.3. Решение — за владельцем.

| # | Что | Цена | Если не делать |
|:--|:--|:--|:--|
| 6 | **Д-6**: `Schedule::command('horizon:snapshot')->everyFiveMinutes()` | 1 строка | графики метрик Horizon пусты навсегда |
| 7 | **Д-7**: `pulse:check` (или хотя бы `pulse:trim`) в расписание | 1–2 строки | `pulse_aggregates` (4198 строк / 2952 kB на 2026-08-05) растут без предела |
| 8 | **Д-8**: `queue:prune-failed` + строка `failed_jobs` в `pipeline:stuck-check` ЛИБО D-запись «только ручной разбор» | 1–2 строки + 1 метод | второй носитель отказа копится и молчит |

### 6.3 Отдельный план (не предлагается в P7.7)

Д-10 (наложение расписанных crawler-команд — нужен дизайн claim'а, не однострочник) · Д-11
(`routes/console.php` в БД на каждый вызов) · Д-12 (`Http::preventStrayRequests()`) · два
непокрытых теста команд (`procurement:convert-xlsx-to-pdf`, `crawler:run-delta`) · очередная
семантика job'ов через реальную очередь · тесты расписания · развязка ретрая скачивания от
refetch-расписания закупки (Д-5, вторая половина) · перенос стадии скачивания в job (§2.5).

Основание — D59: сплошное покрытие 29+ команд и 5 job'ов сопоставимо по объёму с отдельной
фазой; при сомнении — в пользу отдельного плана.
