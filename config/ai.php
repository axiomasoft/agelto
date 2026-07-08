<?php

declare(strict_types=1);

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
];
