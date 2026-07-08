<?php

declare(strict_types=1);
use App\Services\Procurement\Prepare\ArchiveStrategy;
use App\Services\Procurement\Prepare\ImageStrategy;
use App\Services\Procurement\Prepare\PassthroughStrategy;
use App\Services\Procurement\Prepare\SpreadsheetStrategy;

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
        'proxy' => env('AI_GEMINI_PROXY'),

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
    | Error-блок / backoff (P4.1)
    |--------------------------------------------------------------------------
    |
    | Единая формула экспоненциального backoff с потолком для ВСЕХ jobs пайплайна
    | (`App\Models\Procurement\Concerns\HasErrorBlock`) — v1-урок: разнобой из трёх формул
    | по job'ам (research/A §Слабые) был источником багов. `max_attempts` — порог
    | `failed_permanently` (dead-letter, retry-команда P4.3 их не подбирает).
    |
    */
    'retry' => [
        'max_attempts' => (int) env('AI_RETRY_MAX_ATTEMPTS', 5),
        'max_backoff_minutes' => (int) env('AI_RETRY_MAX_BACKOFF_MINUTES', 240),
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
    */
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

        // Преемники (D27) — не маршрутизируются ни одним сидом P7.2 до решения владельца
        // о дате переключения (handoff open_questions); цены — research/E §4, справочно.
        // 'gemini-3.1-flash-lite' => ['input_per_1m' => 0.25, 'output_per_1m' => 1.50, 'cached_per_1m' => 0.025, ...],
        // 'gemini-3.5-flash'      => ['input_per_1m' => 1.50, 'output_per_1m' => 9.00, 'cached_per_1m' => 0.15, ...],
        // 'gemini-3.1-pro-preview'=> ['input_per_1m' => 2.00, 'output_per_1m' => 12.00, 'cached_per_1m' => 0.20, ...],
    ],

    /*
    |--------------------------------------------------------------------------
    | Подготовка документов (P4.2, ТЗ 03 §3.3)
    |--------------------------------------------------------------------------
    |
    | `strategies` — реестр `PreparationStrategy`, порядок важен: первая подошедшая по
    | `canProcess()` обрабатывает версию; `PassthroughStrategy` — ловит всё, всегда последняя.
    | xlsx size-gate (D35, research/L finding 5): `xlsx_max_mb`/`xlsx_max_rows` проверяются
    | ДО полной загрузки файла — сверх лимита `unprocessable(too_large)`, не OOM. Сама конверсия
    | Excel→PDF — в отдельном PHP-процессе (`procurement:convert-xlsx-to-pdf`) с явным
    | `xlsx_convert_memory_limit`, чтобы OOM не убивал соседние jobs воркера.
    |
    */
    'prepare' => [
        'strategies' => [
            ArchiveStrategy::class,
            SpreadsheetStrategy::class,
            ImageStrategy::class,
            PassthroughStrategy::class,
        ],

        'xlsx_max_rows' => (int) env('AI_PREPARE_XLSX_MAX_ROWS', 20000),
        'xlsx_max_mb' => (int) env('AI_PREPARE_XLSX_MAX_MB', 25),
        'xlsx_convert_memory_limit' => env('AI_PREPARE_XLSX_CONVERT_MEMORY_LIMIT', '512M'),
        'xlsx_convert_timeout_seconds' => (int) env('AI_PREPARE_XLSX_CONVERT_TIMEOUT_SECONDS', 120),

        /*
        | Zip-бомба (Code Guidance P4.2): `max_depth` — вложенность архив-в-архиве по цепочке
        | `parent_version_id`; `max_files`/`max_total_mb` — заявленные ZipArchive-заголовками
        | (без распаковки) число файлов и суммарный uncompressed-размер.
        */
        'archive' => [
            'max_depth' => (int) env('AI_PREPARE_ARCHIVE_MAX_DEPTH', 2),
            'max_files' => (int) env('AI_PREPARE_ARCHIVE_MAX_FILES', 200),
            'max_total_mb' => (int) env('AI_PREPARE_ARCHIVE_MAX_TOTAL_MB', 500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Скрининг (гейт цепочки стадий, P4.2 → P5)
    |--------------------------------------------------------------------------
    |
    | `enabled` — выключен по умолчанию: `ScreeningJob` (полная реализация — P5.1/P5.2) ещё
    | не готов боевым образом; `PrepareDocumentJob` диспатчит его за этим флагом (владелец
    | цепочки стадий — P4.2, ТЗ 03 §4). `pages` — сколько первых страниц PDF резать перед
    | загрузкой (D6); `max_tokens` — маленький потолок ответа скрининга (Code Guidance P5.1,
    | классификация не требует длинного вывода).
    |
    | Ветвление (P5.2, `ScreeningOutcomeHandler`, ТЗ 03 §3.4/D36): `ignore_rules` — подстроки
    | (регистронезависимо) в `Procurement.title`, v1-канон `config('platform.estimate.ignore_rules')`
    | (пример «Поставка продуктов питания») — категорический `ignored` независимо от классификации
    | модели. `confidence_low`/`confidence_high` — borderline-зона: ниже low → `unknown`→`ignored`
    | (не рисковать extraction-токенами), выше high → обычное ветвление по `document_type`, между
    | ними — `borderline_action` (`escalate`: один повторный скрининг на маршрут классом выше;
    | `flag`, default: `needs_review=true` в `analysis.result`, статус `screened`, без extraction).
    |
    */
    'screening' => [
        'enabled' => (bool) env('AI_SCREENING_ENABLED', false),
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
    | `enabled` — выключен по умолчанию: `ScreeningOutcomeHandler` диспатчит `EstimateExtractionJob`
    | для `document_type=estimate` за этим флагом (тот же паттерн, что `ai.screening.enabled` у
    | `PrepareDocumentJob`, P4.2).
    |
    | `windowed_enabled` (D40, default false) — preflight (`ExtractionPreflight`, D29) сверх
    | лимитов маршрута ИЛИ выбирает оконный путь (при true), ИЛИ терминирует
    | `unprocessable(provider_limit_*)` (при false — до реализации `WindowedChunkSource`, P6.4);
    | `ExtractionOrchestrator` (P6.1) исполняет ТОЛЬКО row-range — при `windowed_enabled=true`
    | без P6.4 бросает исключение (job-retry), не молча деградирует.
    |
    | `row_range_size`/`min_row_range_size` — стартовое/минимальное окно `extract_rows`
    | (калибровка на реальных сметах — Открытый вопрос `03` §7); окно уменьшается вдвое (до
    | минимума) при MAX_TOKENS (Implementation Rules P6.1). `checkpoint_ranges`/
    | `checkpoint_seconds` — порог self-requeue (D31): после M единиц прогресса (паспорт/
    | диапазон/итоги) ИЛИ T секунд — что раньше — `EstimateExtractionJob` персистит progress и
    | ре-диспатчит себя; `checkpoint_seconds` ОБЯЗАН быть строго меньше `retry_after` очереди
    | `ai_extraction` (`config/horizon.php`, инвариант D26). `max_estimates_per_document` —
    | защитный потолок цикла обнаружения смет паспорт-шагом (безопасность от зацикливания на
    | некорректном ответе модели, не бизнес-лимит).
    |
    */
    'extraction' => [
        'enabled' => (bool) env('AI_EXTRACTION_ENABLED', false),
        'windowed_enabled' => (bool) env('AI_EXTRACTION_WINDOWED_ENABLED', false),
        'row_range_size' => (int) env('AI_EXTRACTION_ROW_RANGE_SIZE', 30),
        'min_row_range_size' => (int) env('AI_EXTRACTION_MIN_ROW_RANGE_SIZE', 5),
        'checkpoint_ranges' => (int) env('AI_EXTRACTION_CHECKPOINT_RANGES', 20),
        'checkpoint_seconds' => (int) env('AI_EXTRACTION_CHECKPOINT_SECONDS', 240),
        'max_estimates_per_document' => (int) env('AI_EXTRACTION_MAX_ESTIMATES_PER_DOCUMENT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Мост crawling → пайплайн документов (P4.3, ТЗ 03 §4)
    |--------------------------------------------------------------------------
    |
    | `dispatch_batch_limit` — лимит немедленного dispatch() по одной закупке (прямой путь,
    | сразу после `FetchDetails`); `backlog_batch_limit` — лимит everyMinute-поллера
    | (research/L finding 12: страховка на пропущенный/несработавший прямой путь, не основной
    | канал); `retry_batch_limit` — дефолт `--limit` у `pipeline:retry-failed` за один тик.
    |
    */
    'pipeline' => [
        'dispatch_batch_limit' => (int) env('AI_PIPELINE_DISPATCH_BATCH_LIMIT', 100),
        'backlog_batch_limit' => (int) env('AI_PIPELINE_BACKLOG_BATCH_LIMIT', 100),
        'retry_batch_limit' => (int) env('AI_PIPELINE_RETRY_BATCH_LIMIT', 50),
    ],
];
