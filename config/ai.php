<?php

declare(strict_types=1);
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Services\Procurement\Analyzers\Estimate\Core\EstimateAnalyzer;

$defaultToolTimeoutSeconds = (int) env('AI_TOOLS_DEFAULT_TIMEOUT_SECONDS', 180);

return [
    /*
    |--------------------------------------------------------------------------
    | Gemini transport
    |--------------------------------------------------------------------------
    |
    | Ключ/base URL читает Prism (`GEMINI_API_KEY`/`GEMINI_URL`). Здесь — тайминги
    | Files API (полинг upload до ACTIVE)
    | и TTL создаваемых explicit context cache по умолчанию (`SupportsContextCaching`).
    |
    */
    'gemini' => [
        // Таймаут generateContent учитывает reasoning до начала ответа.
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
        | Троттлинг вызовов: при `max<=0` или в юнит-тестах запрос
        | выполняется без троттла. Стартовые лимиты не откалиброваны против реальной квоты
        | провайдера — default выключен, включается явным env в проде.
        */
        'rate_limit' => [
            'max' => (int) env('AI_GEMINI_RATE_LIMIT_MAX', 0),
            'every_seconds' => (int) env('AI_GEMINI_RATE_LIMIT_EVERY_SECONDS', 60),
            'block_seconds' => (int) env('AI_GEMINI_RATE_LIMIT_BLOCK_SECONDS', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Capability-матрица моделей
    |--------------------------------------------------------------------------
    |
    | Цены `$/1M` токенов (Standard-тариф, без Batch);
    | `cached_per_1m` — тариф implicit/explicit cache-hit токенов (LlmCallRecorder::costMicros).
    | Preflight сверяет `max_request_file_mb`/`max_pages`/`supports_files_api` до upload.
    | `eol_date` и `successor` задают настроенную дату окончания поддержки и модель-преемника.
    |
    | Цена gemini-2.5-pro задана для ≤200k input-токенов; тариф большего контекста
    | здесь не моделируется.
    |
    | `max_output_tokens` — потолок ВЫВОДА модели (`maxOutputTokens`, включает
    | reasoning-токены thinking-моделей), вход fail-fast проверки `LlmRouteResolver`. Ставится
    | только моделям с подтверждённым лимитом; отсутствие ключа отключает эту проверку.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | EOL-мониторинг моделей (`process:diagnostics`)
    |--------------------------------------------------------------------------
    |
    | Сколько дней до `capabilities.<model>.eol_date` считать «пора алертить» для модели
    | активного маршрута (`llm_routes.is_active`).
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

        // Транспортные лимиты 3.x унаследованы от 2.5 как рабочее допущение;
        // при смене модели их нужно сверять с документацией провайдера.
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
    | Отчёт стоимости (`ai:cost-report`)
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
    | Сверка с биллингом провайдера (`ai:cost-reconcile`)
    |--------------------------------------------------------------------------
    |
    | `threshold_percent` — порог расхождения (от бОльшего из наш/выгрузка), выше которого команда
    | печатает предупреждение (падает только под `--fail-on-breach`). Порог учитывает
    | округление, изменение курса и суточный лаг биллинга.
    |
    */
    'reconcile' => [
        'threshold_percent' => (float) env('AI_RECONCILE_THRESHOLD_PERCENT', 10.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Подготовка документов
    |--------------------------------------------------------------------------
    |
    | Format selection lives in `DocumentFormatRegistry`; an unknown extension or a MIME/signature
    | mismatch becomes an explicit terminal outcome and never falls through to a catch-all.
    | xlsx size-gate: `xlsx_max_mb`/`xlsx_max_rows` проверяются
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

        // Бюджет разреженного обхода `TextGridRenderer`: сумма существующих
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

        // Лимит памяти импорта дополняет ограничения обхода ячеек в TextGridRenderer.
        'gold_import_memory_limit' => env('AI_PREPARE_GOLD_IMPORT_MEMORY_LIMIT', '1024M'),

        /*
        | Zip-бомба: `max_depth` — вложенность архив-в-архиве по цепочке
        | `parent_version_id`; остальные лимиты проверяются до извлечения и повторно по
        | фактическому потоку одной записи.
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
    | Системные бинари фабрики представлений
    |--------------------------------------------------------------------------
    |
    | Пути/имена — env-переопределяемые (`ai:tools:doctor` их и проверяет). `default_timeout_seconds`
    | — таймаут по умолчанию для Process-вызовов конвертеров, если стратегия не
    | переопределяет его точечно.
    |
    */
    'tools' => [
        'soffice_bin' => env('AI_TOOLS_SOFFICE_BIN', 'soffice'),
        'sevenzip_bin' => env('AI_TOOLS_SEVENZIP_BIN', '7z'),
        'unar_bin' => env('AI_TOOLS_UNAR_BIN', 'unar'),
        'lsar_bin' => env('AI_TOOLS_LSAR_BIN', 'lsar'),
        'tesseract_bin' => env('AI_TOOLS_TESSERACT_BIN', 'tesseract'),
        'ocrmypdf_bin' => env('AI_TOOLS_OCRMYPDF_BIN', 'ocrmypdf'),
        'pdftotext_bin' => env('AI_TOOLS_PDFTOTEXT_BIN', 'pdftotext'),
        'pdfinfo_bin' => env('AI_TOOLS_PDFINFO_BIN', 'pdfinfo'),
        'gs_bin' => env('AI_TOOLS_GS_BIN', 'gs'),
        'default_timeout_seconds' => $defaultToolTimeoutSeconds,

        /*
        | OCR сканов. Языки — `rus+eng`: штампы и единицы могут содержать латиницу.
        | OCR имеет отдельный таймаут: распознавание многостраничного скана занимает минуты.
        */
        'ocr_languages' => env('AI_TOOLS_OCR_LANGUAGES', 'rus+eng'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Скрининг
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
    | Извлечение смет
    |--------------------------------------------------------------------------
    |
    | Offline corpus/eval settings retained for deterministic tooling. Runtime extraction uses
    | config/llm.php at provisioning, then only its sealed coverage strategy and task manifest.
    | Split checkpoints and continuations belong to WorkFinalizer, never self-dispatched jobs.
    |
    */
    'extraction' => [
        'rounding_tolerance' => env('AI_EXTRACTION_ROUNDING_TOLERANCE', '0.00'),
        'coefficient_tolerance' => env('AI_EXTRACTION_COEFFICIENT_TOLERANCE', '0.01'),
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
    | Анализаторы типов документа
    |--------------------------------------------------------------------------
    |
    | Единственный носитель реестра «тип → анализатор»: добавление типа документа = ОДНА
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
    | Датасет эталонов и eval-раннер
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
    | Эталонный корпус смет
    |--------------------------------------------------------------------------
    |
    | Реестр `prc_ai_fixtures` — справочник эталонных ФАЙЛОВ; `disk` — где они физически
    | лежат (`estimates` локально/в тестах, `estimates_s3` на проде). Раскладка внутри диска —
    | конвенция `<CODE>/source.<ext>` для исходного файла и `<CODE>/derived/<представление>`
    | для производных представлений (единственный построитель путей —
    | `Fixture::storagePathFor()`/`derivedPathFor()`). `manifest_path` — SSOT корпуса в git
    | — единственный вход `estimate:fixtures:import`, единственный выход `estimate:fixtures:manifest`.
    | `gold_dir` — где живут `*.gold.json`, тоже в git. `source_dir` — папка-источник
    | НЕ в git (155 МБ реального корпуса), откуда `estimate:fixtures:import` берёт файлы, которых ещё нет
    | в хранилище. Бакет прода создаётся оператором, этот блок его не заводит.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Выбор представления на вход LLM
    |--------------------------------------------------------------------------
    |
    | Правило — ДАННЫЕ, не код: `policy[<расширение исходной версии>]` — упорядоченный список
    | `RepresentationKind`-значений, `RepresentationResolver::forLlm()` идёт по списку и отдаёт
    | ПЕРВОЕ существующее представление версии; ключ `default` — для форматов, которых не было в
    | измеренной подвыборке (`xlsx`/`xls`/`gsfx`). Отсутствие всех перечисленных kind'ов при
    | `fallback_to_source = true` отдаёт оригинал версии, при `false` — `null`.
    |
    | Для `xlsx`/`xls` сначала используется `pdf_alt`, затем `pdf`. Прочие форматы
    | следуют `default`; переносить порядок между форматами без проверки качества нельзя.
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
        // Fallback-цепочка при переносе файлов между дисками —
        // упорядоченный список имён дисков от новых поколений к старым, ДАННЫЕ, не ветка кода.
        // Пустая по умолчанию: поведение не меняется байт-в-байт. Читатель — `FileLocationResolver::forFixture()`.
        'fallback_disks' => [],
        'manifest_path' => base_path('estimates/manifest.json'),
        'gold_dir' => base_path('estimates/gold'),
        'source_dir' => base_path('estimates/files'),
        // Разбиение `tune`/`holdout` петли тюнинга промтов — в git рядом с gold,
        // манифест (`manifest_path`) не расширяется.
        'splits_path' => base_path('estimates/eval-splits.json'),
        // Корень артефактов, пишущихся КОМАНДАМИ корпуса: отчёт корпуса
        // `estimate:gold:build` и дефолт `--out` `estimate:bench:formats`.
        // Каталог создаётся при первой записи (`File::ensureDirectoryExists()`) —
        // `estimates/reports/` в репозитории не существует.
        'reports_dir' => env('AI_FIXTURES_REPORTS_DIR', base_path('estimates/reports')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Экспорт под локальную LLM (`estimate:dataset:export`)
    |--------------------------------------------------------------------------
    |
    | `representation_priority` — порядок kind'ов `prc_document_representations`, из которых
    | `DatasetInputBuilder` берёт ТЕКСТ во вход обучающей строки (первое существующее представление
    | с `mime`, начинающимся на `text/`); `structured_xml` исключён явно самим строителем, в список
    | политики не входит. `max_input_chars` — потолок ЗАДАН ТЗ до данных: сдвиг после первого
    | прогона «чтобы вошло больше» — подгонка, запрещена, изменение только явным изменением политики.
    |
    */
    'dataset_export' => [
        'representation_priority' => ['text_grid', 'text'],
        'max_input_chars' => (int) env('AI_DATASET_EXPORT_MAX_INPUT_CHARS', 300_000),
    ],
];
