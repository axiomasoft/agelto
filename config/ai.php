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

        // Преемники (D27) — не маршрутизируются ни одним сидом P7.2 до решения владельца
        // о дате переключения (handoff open_questions); цены — research/E §4, справочно.
        // 'gemini-3.1-flash-lite' => ['input_per_1m' => 0.25, 'output_per_1m' => 1.50, 'cached_per_1m' => 0.025, ...],
        // 'gemini-3.5-flash'      => ['input_per_1m' => 1.50, 'output_per_1m' => 9.00, 'cached_per_1m' => 0.15, ...],
        // 'gemini-3.1-pro-preview'=> ['input_per_1m' => 2.00, 'output_per_1m' => 12.00, 'cached_per_1m' => 0.20, ...],
    ],

    /*
    |--------------------------------------------------------------------------
    | Отчёт стоимости (P7.2, `ai:cost-report`, ТЗ 04 §6)
    |--------------------------------------------------------------------------
    |
    | `cost_micros` в `prc_ai_llm_calls` — доллары ×1e6 (`LlmCallRecorder::costMicros()`, цены
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
    | `windowed_enabled` (D40, default TRUE — `WindowedChunkSource` реализован, P6.4) —
    | preflight (`ExtractionPreflight`, D29) сверх лимитов маршрута выбирает оконный путь (при
    | true) ИЛИ терминирует `unprocessable(provider_limit_*)` (при false — ручной откат до
    | калибровки/инцидента). `ExtractionOrchestrator::runWindowed()` (P6.4) исполняет
    | трёхстадийный discovery→сегменты→per-segment разбор.
    |
    | `row_range_size`/`min_row_range_size` — стартовое/минимальное окно `extract_rows`
    | (калибровка на реальных сметах — Открытый вопрос `03` §7); окно уменьшается вдвое (до
    | минимума) при MAX_TOKENS (Implementation Rules P6.1). `checkpoint_ranges`/
    | `checkpoint_seconds` — порог self-requeue (D31): после M единиц прогресса (паспорт/
    | диапазон/итоги/discovery-окно) ИЛИ T секунд — что раньше — `EstimateExtractionJob`
    | персистит progress и ре-диспатчит себя; `checkpoint_seconds` ОБЯЗАН быть строго меньше
    | `retry_after` очереди `ai_extraction` (`config/horizon.php`, инвариант D26).
    | `max_estimates_per_document` — защитный потолок цикла обнаружения смет паспорт-шагом
    | row-range пути (безопасность от зацикливания на некорректном ответе модели, не
    | бизнес-лимит). `window_pages`/`window_overlap_pages` — размер/нахлёст страничного окна
    | оконного пути (D30, P6.4), используется и на стадии discovery, и на per-segment
    | rows-разбивке (Code Guidance P6.4); `totals_tail_pages` — хвост сегмента, запрашиваемый
    | под `extract_totals` (§4.2 Стадия 3). Калибровка всех трёх — Pending Work до датасета P7.4.
    |
    */
    'extraction' => [
        'enabled' => (bool) env('AI_EXTRACTION_ENABLED', false),
        'windowed_enabled' => (bool) env('AI_EXTRACTION_WINDOWED_ENABLED', true),
        'row_range_size' => (int) env('AI_EXTRACTION_ROW_RANGE_SIZE', 30),
        'min_row_range_size' => (int) env('AI_EXTRACTION_MIN_ROW_RANGE_SIZE', 5),
        'checkpoint_ranges' => (int) env('AI_EXTRACTION_CHECKPOINT_RANGES', 20),
        'checkpoint_seconds' => (int) env('AI_EXTRACTION_CHECKPOINT_SECONDS', 240),
        'max_estimates_per_document' => (int) env('AI_EXTRACTION_MAX_ESTIMATES_PER_DOCUMENT', 20),
        'window_pages' => (int) env('AI_EXTRACTION_WINDOW_PAGES', 3),
        'window_overlap_pages' => (int) env('AI_EXTRACTION_WINDOW_OVERLAP_PAGES', 1),
        'totals_tail_pages' => (int) env('AI_EXTRACTION_TOTALS_TAIL_PAGES', 2),
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

        /*
        | Пороги `pipeline:stuck-check` (P7.3, ТЗ 03 §5). `analysis_stuck_minutes` — screening
        | (single-shot, v1-канон ANALYSIS_STUCK_MINUTES=30); `checkpoint_stuck_minutes` —
        | extraction ОТДЕЛЬНО (D31): self-requeue-цепочка легитимно проходит через паузы между
        | checkpoint-прогонами дольше единичного вызова, общий с screening порог давал бы
        | ложные срабатывания. `document_stuck_minutes` — застревание документа в
        | downloaded/prepared/screened (мост между стадиями, ревью K п.20). `spike_window_hours`
        | — общее окно для счётчиков всплесков (unknown/unprocessable/расхождение rows);
        | `*_spike_threshold`/`estimate_mismatch_threshold` — сами пороги счётчиков.
        | `alert_antispam_minutes` — не чаще одного Telegram-сообщения на одну и ту же находку
        | (`PipelineAlert::notify()`, Code Guidance).
        */
        'analysis_stuck_minutes' => (int) env('AI_PIPELINE_ANALYSIS_STUCK_MINUTES', 30),
        'checkpoint_stuck_minutes' => (int) env('AI_PIPELINE_CHECKPOINT_STUCK_MINUTES', 60),
        'document_stuck_minutes' => (int) env('AI_PIPELINE_DOCUMENT_STUCK_MINUTES', 60),
        'spike_window_hours' => (int) env('AI_PIPELINE_SPIKE_WINDOW_HOURS', 24),
        'unknown_spike_threshold' => (int) env('AI_PIPELINE_UNKNOWN_SPIKE_THRESHOLD', 20),
        'unprocessable_spike_threshold' => (int) env('AI_PIPELINE_UNPROCESSABLE_SPIKE_THRESHOLD', 5),
        'estimate_mismatch_threshold' => (int) env('AI_PIPELINE_ESTIMATE_MISMATCH_THRESHOLD', 5),
        'alert_antispam_minutes' => (int) env('AI_PIPELINE_ALERT_ANTISPAM_MINUTES', 60),

        /*
        | SQL-алерты стадий (P7.5, D25 Intent п.5) — подключены к тому же `PipelineAlert`
        | (P7.3), НЕ второй транспорт. `backlog_threshold` — документов в
        | downloaded/prepared/screened одновременно (очередь, не возраст — тот класс сигнала,
        | что уже даёт `stuckDocuments()`, но по количеству, не по времени). `failure_rate_*` —
        | доля failed среди succeeded+failed анализов (оба шага) за окно. `no_completions_minutes`
        | — ни одного succeeded-анализа за окно ПРИ НЕПУСТОМ backlog (иначе «пайплайну просто
        | нечего делать» ложно читалось бы как «пайплайн встал»).
        */
        'backlog_threshold' => (int) env('AI_PIPELINE_BACKLOG_THRESHOLD', 200),
        'failure_rate_window_hours' => (int) env('AI_PIPELINE_FAILURE_RATE_WINDOW_HOURS', 1),
        'failure_rate_threshold_percent' => (float) env('AI_PIPELINE_FAILURE_RATE_THRESHOLD_PERCENT', 50.0),
        'no_completions_minutes' => (int) env('AI_PIPELINE_NO_COMPLETIONS_MINUTES', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Датасет эталонов и eval-раннер (P7.4, ТЗ 04 §5)
    |--------------------------------------------------------------------------
    |
    | `dataset_version` — дефолтная метка `prc_ai_eval_runs.dataset_version`, когда `ai:eval:run`
    | вызван без `--dataset-version` (владелец меняет по факту снапшота golden-датасета).
    | `max_orchestrator_iterations` — защитный потолок цикла синхронных повторов
    | `ExtractionOrchestrator::run()` внутри `ai:eval:run` (checkpoint-requeue D31 здесь БЕЗ
    | self-dispatch через очередь — команда синхронна, повторяет вызов в том же процессе).
    |
    */
    'eval' => [
        'dataset_version' => env('AI_EVAL_DATASET_VERSION', 'v1'),
        'max_orchestrator_iterations' => (int) env('AI_EVAL_MAX_ORCHESTRATOR_ITERATIONS', 200),
    ],
];
