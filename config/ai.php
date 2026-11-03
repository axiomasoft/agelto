<?php

declare(strict_types=1);
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Services\Procurement\Analyzers\Estimate\EstimateAnalyzer;

$defaultToolTimeoutSeconds = (int) env('AI_TOOLS_DEFAULT_TIMEOUT_SECONDS', 180);

return [
    /*
    |--------------------------------------------------------------------------
    | Gemini transport
    |--------------------------------------------------------------------------
    |
    | Ключ/base URL — читает сам Prism (`GEMINI_API_KEY`/`GEMINI_URL`, config/prism.php,
    | не публикуется). Здесь — то, что Prism НЕ покрывает: исходящий прокси (v1-канон,
    | RU-ограничения, `Old/app/Services/GeminiService.php::buildClient()`), тайминги
    | Files API (P3.2 полинг upload до ACTIVE — Prism не реализует Files API вовсе)
    | и TTL создаваемых explicit context cache по умолчанию (`SupportsContextCaching`).
    |
    */
    'gemini' => [
        // Prism-default (`PRISM_REQUEST_TIMEOUT`, config/prism.php) — 30с, посчитан под 2.5-линейку
        // без `thinking`-бюджета; `gemini-3.x` тратит время на reasoning ДО generateContent-ответа
        // (боевой прогон P6.2 2026-07-28: систематические cURL timeout 30с на части эталонов,
        // research/12-format-benchmark.md §5) — таймаут этого запроса переопределён отдельно.
        'request_timeout_seconds' => (int) env('AI_GEMINI_REQUEST_TIMEOUT_SECONDS', 150),

        'files' => [
            'base_url' => 'https://generativelanguage.googleapis.com',
            'poll_interval_ms' => (int) env('AI_GEMINI_FILE_POLL_INTERVAL_MS', 500),
            'poll_timeout_seconds' => (int) env('AI_GEMINI_FILE_POLL_TIMEOUT_SECONDS', 60),
        ],

        'cache' => [
            'default_ttl_seconds' => (int) env('AI_GEMINI_CACHE_DEFAULT_TTL_SECONDS', 3600),
        ],

        /*
        | Троттлинг вызовов (research/E §6, ТЗ 04 §6) — аналог v1 `Redis::throttle('gemini:generate')`
        | (`Old/app/Services/GeminiService.php::withRateLimit()`); `max<=0` ИЛИ юнит-тесты —
        | выполняется без троттла. Стартовые лимиты не откалиброваны против реальной квоты
        | провайдера (Pending Work P3.2) — default выключен, включается явным env в проде.
        */
        'rate_limit' => [
            'max' => (int) env('AI_GEMINI_RATE_LIMIT_MAX', 0),
            'every_seconds' => (int) env('AI_GEMINI_RATE_LIMIT_EVERY_SECONDS', 60),
            'block_seconds' => (int) env('AI_GEMINI_RATE_LIMIT_BLOCK_SECONDS', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Capability-матрица моделей (D14, D27, D29)
    |--------------------------------------------------------------------------
    |
    | Цены `$/1M` токенов (research/E §4, Standard-тариф, без Batch — D7 вне плана);
    | `cached_per_1m` — тариф implicit/explicit cache-hit токенов (LlmCallRecorder::costMicros).
    | Транспортные лимиты `max_request_file_mb`/`max_pages`/`supports_files_api` — D29,
    | preflight оркестратора извлечения (P6.1) сверяет их ДО upload. `eol_date`/`successor` —
    | D27: линейка 2.5 гаснет 2026-10-16, P7.3 алертит по приближению к дате, P7.2 сидирует
    | закомментированную 3.x-строку-преемника рядом с активным маршрутом.
    |
    | gemini-2.5-pro цена указана для тарифа ≤200k input-токенов (research/E §4); тариф >200k
    | (2.50/15.00) здесь не моделируется — построчные диапазоны (D3/D10) держат вызов далеко
    | от этого порога, калибровка — если реальная практика это опровергнет (YAGNI).
    |
    | `max_output_tokens` (D98/D99, P2.9) — потолок ВЫВОДА модели (`maxOutputTokens`, включает
    | reasoning-токены thinking-моделей), вход fail-fast проверки `LlmRouteResolver`. Ставится
    | ТОЛЬКО моделям, чьё значение подтверждено RAG (первично, не по аналогии с соседней моделью
    | линейки) — отсутствие ключа означает «не проверяем», это честнее выдуманной цифры (та же
    | дисциплина, что у транспортных лимитов 3.x ниже).
    |
    */
    /*
    |--------------------------------------------------------------------------
    | EOL-мониторинг моделей (D27, P7.3 `pipeline:stuck-check`)
    |--------------------------------------------------------------------------
    |
    | Сколько дней до `capabilities.<model>.eol_date` считать «пора алертить» для модели
    | АКТИВНОГО маршрута (`prc_ai_routes.is_active`) — переключение готово заранее
    | (`AiRoutingSeeder::routes()` уже несёт закомментированные 3.x-строки, P7.2).
    |
    */
    'model_eol_warn_days' => (int) env('AI_MODEL_EOL_WARN_DAYS', 30),

    'capabilities' => [
        'gemini-2.5-flash-lite' => [
            'input_per_1m' => 0.10,
            'output_per_1m' => 0.40,
            'cached_per_1m' => 0.01,
            'max_request_file_mb' => 50,
            'max_pages' => 1000,
            'supports_files_api' => true,
            'eol_date' => '2026-10-16',
            'successor' => 'gemini-3.1-flash-lite',
        ],
        'gemini-2.5-flash' => [
            'input_per_1m' => 0.30,
            'output_per_1m' => 2.50,
            'cached_per_1m' => 0.03,
            'max_request_file_mb' => 50,
            'max_pages' => 1000,
            'supports_files_api' => true,
            'eol_date' => '2026-10-16',
            'successor' => 'gemini-3.5-flash',
        ],
        'gemini-2.5-pro' => [
            'input_per_1m' => 1.25,
            'output_per_1m' => 10.00,
            'cached_per_1m' => 0.125,
            'max_request_file_mb' => 50,
            'max_pages' => 1000,
            'supports_files_api' => true,
            'eol_date' => '2026-10-16',
            'successor' => 'gemini-3.1-pro-preview',
        ],

        // Преемники (D27) — активны с D123 (2026-07-28): линейка 2.5 недоступна ключу владельца
        // целиком («404 no longer available to new users», признанный Google-баг для новых
        // GCP-проектов, RAG:✅ Perplexity 2026-07-28), маршрутизированы AiRoutingSeeder раньше
        // официального EOL 2.5 (2026-10-16). Цены сверены ai.google.dev/gemini-api/docs/pricing
        // 2026-07-28 (RAG:✅). Транспортные лимиты (50 МБ/1000 стр., Files API) не документированы
        // отдельно под 3.x на дату сверки — унаследованы от 2.5-линейки как рабочее допущение
        // (тот же провайдер/API-семейство); расхождение, если найдётся, — предмет отдельной D#.
        'gemini-3.1-flash-lite' => [
            'input_per_1m' => 0.25,
            'output_per_1m' => 1.50,
            'cached_per_1m' => 0.025,
            'max_request_file_mb' => 50,
            'max_pages' => 1000,
            'supports_files_api' => true,
            'eol_date' => '2027-05-07',
            'successor' => 'gemini-3.5-flash-lite',
        ],
        'gemini-3.5-flash' => [
            'input_per_1m' => 1.50,
            'output_per_1m' => 9.00,
            'cached_per_1m' => 0.15,
            'max_request_file_mb' => 50,
            'max_pages' => 1000,
            'supports_files_api' => true,
            'max_output_tokens' => 65536,
            'eol_date' => null,
            'successor' => null,
        ],
        'gemini-3.1-pro-preview' => [
            'input_per_1m' => 2.00,
            'output_per_1m' => 12.00,
            'cached_per_1m' => 0.20,
            'max_request_file_mb' => 50,
            'max_pages' => 1000,
            'supports_files_api' => true,
            'eol_date' => null,
            'successor' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Отчёт стоимости (P7.2, `ai:cost-report`, ТЗ 04 §6)
    |--------------------------------------------------------------------------
    |
    | `cost_micros` в reporting projection — доллары ×1e6 (цены
    | `capabilities.*` уже в $). `rub_per_usd` — статический курс для дублирующей рублёвой колонки
    | отчёта (никакого внешнего FX API в этом item'е, YAGNI) — меняется по факту env, не привязан
    | к ЦБ РФ.
    |
    */
    'cost_report' => [
        'rub_per_usd' => (float) env('AI_COST_REPORT_RUB_PER_USD', 90.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Сверка с биллингом провайдера (P4.4, `ai:cost-reconcile`)
    |--------------------------------------------------------------------------
    |
    | `threshold_percent` — порог расхождения (от бОльшего из наш/выгрузка), выше которого команда
    | печатает предупреждение (падает только под `--fail-on-breach`). Дефолт 10 %: расхождение
    | инцидента D126 (`2026.07.26-EST-TRAIN`, $2.30 по трекингу против ~$12 по биллингу Google) было
    | почти пятикратным — порог обязан ловить кратно меньшее; ниже ~10 % шум округления, курса и
    | суточного лага биллинга даёт ложные срабатывания.
    |
    */
    'reconcile' => [
        'threshold_percent' => (float) env('AI_RECONCILE_THRESHOLD_PERCENT', 10.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Подготовка документов (P4.2, ТЗ 03 §3.3)
    |--------------------------------------------------------------------------
    |
    | Format selection lives in `DocumentFormatRegistry`; an unknown extension or a MIME/signature
    | mismatch becomes an explicit terminal outcome and never falls through to a catch-all.
    | xlsx size-gate (D35, research/L finding 5): `xlsx_max_mb`/`xlsx_max_rows` проверяются
    | ДО полной загрузки файла — сверх лимита `unprocessable(too_large)`, не OOM. Сама конверсия
    | Excel→PDF — в отдельном PHP-процессе (`procurement:convert-xlsx-to-pdf`) с явным
    | `xlsx_convert_memory_limit`, чтобы OOM не убивал соседние jobs воркера.
    |
    */
    'prepare' => [
        'xlsx_max_rows' => (int) env('AI_PREPARE_XLSX_MAX_ROWS', 20000),
        'xlsx_max_mb' => (int) env('AI_PREPARE_XLSX_MAX_MB', 25),
        'xlsx_convert_memory_limit' => env('AI_PREPARE_XLSX_CONVERT_MEMORY_LIMIT', '512M'),
        'xlsx_convert_timeout_seconds' => (int) env('AI_PREPARE_XLSX_CONVERT_TIMEOUT_SECONDS', 120),

        // Бюджет разреженного обхода `TextGridRenderer` (P2.8, D95/D96): сумма существующих
        // координат листа + площадь merge-диапазонов, посчитанная ДО сбора карты значений;
        // превышение — тот же контракт «лимит инструмента», что у xlsx_max_rows/xlsx_max_mb.
        // Дефолт — запас ≈68× над худшим документом корпуса (29 464 ячейки при 32 МБ памяти
        // прототипа), ловит патологию вроде merge на весь лист, а не боевые сметы.
        'xlsx_max_cells' => (int) env('AI_PREPARE_XLSX_MAX_CELLS', 2_000_000),
        'xlsx_max_zip_entries' => (int) env('AI_PREPARE_XLSX_MAX_ZIP_ENTRIES', 10_000),
        'xlsx_max_decoded_mb' => (int) env('AI_PREPARE_XLSX_MAX_DECODED_MB', 128),
        'xlsx_max_compression_ratio' => (int) env('AI_PREPARE_XLSX_MAX_COMPRESSION_RATIO', 100),
        'gsfx_max_core_xml_mb' => (int) env('AI_PREPARE_GSFX_MAX_CORE_XML_MB', 16),
        'gsfx_parse_memory_limit' => env('AI_PREPARE_GSFX_PARSE_MEMORY_LIMIT', '256M'),
        'gsfx_parse_timeout_seconds' => (int) env('AI_PREPARE_GSFX_PARSE_TIMEOUT_SECONDS', 60),

        // Лимит памяти `estimate:gold:import --prepare` (вынос литерала из GoldImportCommand,
        // P2.8, D96): значение не меняется этим выносом — квадратичный обход-корень блокера
        // чинится в TextGridRenderer, этот лимит остаётся вторым рубежом на MERGE_HEAVY книгах.
        'gold_import_memory_limit' => env('AI_PREPARE_GOLD_IMPORT_MEMORY_LIMIT', '1024M'),

        /*
        | Zip-бомба (Code Guidance P4.2): `max_depth` — вложенность архив-в-архиве по цепочке
        | `parent_version_id`; остальные лимиты проверяются до извлечения и повторно по
        | фактическому потоку одной записи (P6.3, D31).
        */
        'archive' => [
            'max_depth' => (int) env('AI_PREPARE_ARCHIVE_MAX_DEPTH', 2),
            'max_files' => (int) env('AI_PREPARE_ARCHIVE_MAX_FILES', 200),
            'max_total_mb' => (int) env('AI_PREPARE_ARCHIVE_MAX_TOTAL_MB', 500),
            'max_entry_mb' => (int) env('AI_PREPARE_ARCHIVE_MAX_ENTRY_MB', 100),
            'max_ratio' => (int) env('AI_PREPARE_ARCHIVE_MAX_RATIO', 100),
            'max_path_length' => (int) env('AI_PREPARE_ARCHIVE_MAX_PATH_LENGTH', 240),
            'timeout_seconds' => (int) env('AI_PREPARE_ARCHIVE_TIMEOUT_SECONDS', 180),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Системные бинари фабрики представлений (P3.2, D23/D24)
    |--------------------------------------------------------------------------
    |
    | Пути/имена — env-переопределяемые (`ai:tools:doctor` их и проверяет). `default_timeout_seconds`
    | — таймаут по умолчанию для Process-вызовов конвертеров P3.4–P3.7, если стратегия не
    | переопределяет его точечно.
    |
    */
    'tools' => [
        'soffice_bin' => env('AI_TOOLS_SOFFICE_BIN', 'soffice'),
        'sevenzip_bin' => env('AI_TOOLS_SEVENZIP_BIN', '7z'),
        'unar_bin' => env('AI_TOOLS_UNAR_BIN', 'unar'),
        'tesseract_bin' => env('AI_TOOLS_TESSERACT_BIN', 'tesseract'),
        'ocrmypdf_bin' => env('AI_TOOLS_OCRMYPDF_BIN', 'ocrmypdf'),
        'pdftotext_bin' => env('AI_TOOLS_PDFTOTEXT_BIN', 'pdftotext'),
        'pdfinfo_bin' => env('AI_TOOLS_PDFINFO_BIN', 'pdfinfo'),
        'gs_bin' => env('AI_TOOLS_GS_BIN', 'gs'),
        'default_timeout_seconds' => $defaultToolTimeoutSeconds,

        /*
        | OCR сканов (P3.7, D22). Языки — `rus+eng` (сметы русские, но штампы и единицы часто
        | латиницей). Таймаут ОТДЕЛЬНЫЙ от `default_timeout_seconds`: 86-страничный скан-эталон
        | корпуса распознаётся минутами, а не секундами (замер — `research/04-ocr-ceiling.md`),
        | и общий 180-секундный потолок инструментов сюда не годится.
        */
        'ocr_languages' => env('AI_TOOLS_OCR_LANGUAGES', 'rus+eng'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Скрининг (гейт цепочки стадий, P4.2 → P5)
    |--------------------------------------------------------------------------
    |
    | Legacy offline screening parameters. Runtime analysis reads its sealed recipe and the
    | typed ScreeningPolicySnapshot; controls and Process lanes own execution eligibility.
    |
    */
    'screening' => [
        'pages' => (int) env('AI_SCREENING_PAGES', 4),
        'max_tokens' => (int) env('AI_SCREENING_MAX_TOKENS', 1024),
        'ignore_rules' => array_filter(array_map('trim', explode(',', (string) env('AI_SCREENING_IGNORE_RULES', '')))),
        'confidence_low' => (float) env('AI_SCREENING_CONFIDENCE_LOW', 0.4),
        'confidence_high' => (float) env('AI_SCREENING_CONFIDENCE_HIGH', 0.6),
        'borderline_action' => env('AI_SCREENING_BORDERLINE_ACTION', 'flag'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Извлечение смет (гейт цепочки стадий, P5.2 → P6.1)
    |--------------------------------------------------------------------------
    |
    | Offline corpus/eval settings retained for deterministic tooling. Runtime extraction uses
    | config/llm.php at provisioning, then only its sealed coverage strategy and task manifest.
    | Split checkpoints and continuations belong to WorkFinalizer, never self-dispatched jobs.
    |
    */
    'extraction' => [
        'windowed_enabled' => (bool) env('AI_EXTRACTION_WINDOWED_ENABLED', true),
        'row_range_size' => (int) env('AI_EXTRACTION_ROW_RANGE_SIZE', 30),
        'min_row_range_size' => (int) env('AI_EXTRACTION_MIN_ROW_RANGE_SIZE', 5),
        'checkpoint_ranges' => (int) env('AI_EXTRACTION_CHECKPOINT_RANGES', 20),
        'checkpoint_seconds' => (int) env('AI_EXTRACTION_CHECKPOINT_SECONDS', 120),
        'max_estimates_per_document' => (int) env('AI_EXTRACTION_MAX_ESTIMATES_PER_DOCUMENT', 20),
        'window_pages' => (int) env('AI_EXTRACTION_WINDOW_PAGES', 3),
        'window_overlap_pages' => (int) env('AI_EXTRACTION_WINDOW_OVERLAP_PAGES', 1),
        'totals_tail_pages' => (int) env('AI_EXTRACTION_TOTALS_TAIL_PAGES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Анализаторы типов документа (P2.3, §5.1 контракта document-analyzer-contract)
    |--------------------------------------------------------------------------
    |
    | Единственный носитель реестра «тип → анализатор» (D14): добавление типа документа = ОДНА
    | строка здесь + каталог типа по конвенции `**\Analyzers\<Type>\**`. Значения — ИМЕНА КЛАССОВ
    | строками, а не замыкания: массив обязан переживать `php artisan config:cache`. Инстанс
    | резолвит контейнер (`DocumentAnalyzerRegistry`), поэтому конструкторная инъекция анализатора
    | работает как у любого сервиса. `AiDocumentType::Unknown` здесь запрещён — сборка реестра
    | падает на такой строке (решение «неизвестный тип → ignored» живёт в ядре скрининга).
    |
    */
    'analyzers' => [
        AiDocumentType::Estimate->value => EstimateAnalyzer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Датасет эталонов и eval-раннер (P7.4, ТЗ 04 §5)
    |--------------------------------------------------------------------------
    |
    | `dataset_version` — дефолтная метка `prc_ai_eval_runs.dataset_version`, когда `estimate:eval:run`
    | вызван без `--dataset-version` (владелец меняет по факту снапшота golden-датасета).
    | Live synchronous eval is Unsupported until it has a registered durable owning gate.
    | The remaining iteration setting is retained solely for offline historical reports.
    |
    */
    'eval' => [
        'dataset_version' => env('AI_EVAL_DATASET_VERSION', 'v1'),
        'max_orchestrator_iterations' => (int) env('AI_EVAL_MAX_ORCHESTRATOR_ITERATIONS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Эталонный корпус смет (EST-TRAIN, D1/D17/D18)
    |--------------------------------------------------------------------------
    |
    | Реестр `prc_ai_fixtures` (D6) — справочник эталонных ФАЙЛОВ; `disk` — где они физически
    | лежат (`estimates` локально/в тестах, `estimates_s3` на проде). Раскладка внутри диска —
    | конвенция `<CODE>/source.<ext>` для исходного файла и `<CODE>/derived/<представление>`
    | для производных представлений P3/P6 (единственный построитель путей —
    | `Fixture::storagePathFor()`/`derivedPathFor()`). `manifest_path` — SSOT корпуса в git
    | (D1/D20): единственный вход `estimate:fixtures:import`, единственный выход `estimate:fixtures:manifest`.
    | `gold_dir` — где живут `*.gold.json` (наполняет P5), тоже в git. `source_dir` — папка-источник
    | НЕ в git (155 МБ реального корпуса), откуда `estimate:fixtures:import` берёт файлы, которых ещё нет
    | в хранилище. Бакет прода создаётся оператором (SOP — P9), этот блок его не заводит.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Выбор представления на вход LLM (D3, D52, P6.3, ADR `adr-input-representation.md`)
    |--------------------------------------------------------------------------
    |
    | Правило — ДАННЫЕ, не код: `policy[<расширение исходной версии>]` — упорядоченный список
    | `RepresentationKind`-значений, `RepresentationResolver::forLlm()` идёт по списку и отдаёт
    | ПЕРВОЕ существующее представление версии; ключ `default` — для форматов, которых не было в
    | измеренной подвыборке P6.2 (`xlsx`/`xls`/`gsfx`). Отсутствие всех перечисленных kind'ов при
    | `fallback_to_source = true` отдаёт оригинал версии, при `false` — `null`.
    |
    | `xlsx`/`xls` → `[pdf_alt, pdf]` по боевому прогону P6.2 (`research/12-format-benchmark.md`
    | §4): критерий не выделил строгого победителя, но `pdf_alt` — единственный кандидат,
    | дисквалифицированный РОВНО одним эталоном (`EST-XLSX-KS2-001`, token-limit харнесса,
    | техническая причина, не качество представления) и самый дешёвый среди трёх реальных
    | кандидатов на оставшихся пяти. `pdf` — второй в списке (fallback внутри самого измеренного
    | семейства, ближайший по цифрам). `gsfx` в карте НЕТ: `resolveEffectiveFile()` пропускает
    | только PDF/изображение (D26), и ни один представленный вариант GSFX не бывает успешным
    | структурно — правило тут ничего не меняет, `default` остаётся. Форматы вне подвыборки
    | (`pdf`-сканы, `docx`, …) НЕ обобщаются на основании трёх измеренных форматов — идут по
    | `default` явно (Code Guidance P6.3, анти-подгонка D22/D35/D42).
    |
    */
    'representations' => [
        'policy' => [
            'xlsx' => ['pdf_alt', 'pdf'],
            'xls' => ['pdf_alt', 'pdf'],
            'default' => ['pdf'],
        ],
        'fallback_to_source' => true,
    ],

    'fixtures' => [
        'disk' => env('AI_FIXTURES_DISK', 'estimates'),
        // Fallback-цепочка на окно cutover (P6.4, `research/07-storage-addressing.md` §5) —
        // упорядоченный список имён дисков от новых поколений к старым, ДАННЫЕ, не ветка кода.
        // Пустая по умолчанию: поведение не меняется байт-в-байт. Читатель — `FileLocationResolver::forFixture()`.
        'fallback_disks' => [],
        'manifest_path' => base_path('estimates/manifest.json'),
        'gold_dir' => base_path('estimates/gold'),
        'source_dir' => base_path('estimates/files'),
        // Разбиение `tune`/`holdout` петли тюнинга промтов (P7.5, D119/D120) — в git рядом с gold,
        // манифест (`manifest_path`) не расширяется.
        'splits_path' => base_path('estimates/eval-splits.json'),
        // Корень артефактов, пишущихся КОМАНДАМИ корпуса (D111, P3.3): отчёт корпуса
        // `estimate:gold:build` и дефолт `--out` `estimate:bench:formats` — раньше хардкодили путь
        // в каталог ЧУЖОГО замороженного плана (`plans/2026.07.26-EST-TRAIN/**`), который уедет в
        // архив (D2/D9). Каталог создаётся при первой записи (`File::ensureDirectoryExists()`) —
        // `estimates/reports/` в репозитории не существует.
        'reports_dir' => env('AI_FIXTURES_REPORTS_DIR', base_path('estimates/reports')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Экспорт под локальную LLM (`estimate:dataset:export`, P7.1, D56)
    |--------------------------------------------------------------------------
    |
    | `representation_priority` — порядок kind'ов `prc_document_representations`, из которых
    | `DatasetInputBuilder` берёт ТЕКСТ во вход обучающей строки (первое существующее представление
    | с `mime`, начинающимся на `text/`); `structured_xml` исключён явно самим строителем, в список
    | политики не входит. `max_input_chars` — потолок ЗАДАН ТЗ до данных (D56): сдвиг после первого
    | прогона «чтобы вошло больше» — подгонка, запрещена, изменение только новым D#.
    |
    */
    'dataset_export' => [
        'representation_priority' => ['text_grid', 'text'],
        'max_input_chars' => (int) env('AI_DATASET_EXPORT_MAX_INPUT_CHARS', 300_000),
    ],
];
