<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Партиционированные таблицы (RANGE by time, помесячно)
    |--------------------------------------------------------------------------
    |
    | Единый список для pg_partman part_config (миграция) и для fallback-команды
    | `partitions:maintain` — не дублировать список таблиц в нескольких местах
    | (см. plans/V2-CORE/phases/P1.md P1.9 Code Guidance).
    |
    */
    'tables' => [
        'prc_events' => 'occurred_at',
        'prc_revisions' => 'detected_at',
        'prc_ai_llm_calls' => 'called_at',
        'prc_fetches' => 'fetched_at',
        'prc_operation_logs' => 'created_at',
    ],

    'interval' => '1 month',

    'premake' => 3,
];
