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
        'prc_fetches' => 'fetched_at',
        'prc_operation_logs' => 'created_at',
    ],

    'interval' => '1 month',

    'premake' => 3,

    /*
    |--------------------------------------------------------------------------
    | Retention (P7.7, D33) — DROP старых партиций per-таблица
    |--------------------------------------------------------------------------
    |
    | ТОЛЬКО эфемерные лог-таблицы (Implementation Rules P7.7): `prc_fetches`, `prc_operation_logs`. `prc_events` и `prc_revisions` НАМЕРЕННО
    | отсутствуют в этом списке: это доменная история закупки, а не эфемерный лог
    | (см. `eternal` ниже), поэтому DROP-retention к ним не применяется.
    |
    | `months` — окно хранения партиции (мигрирует в `partman.part_config.retention`
    | как `"<months> months"`, миграция `2026_07_09_100050_set_pg_partman_retention`).
    | `keep_table` — `false` = DROP партиции по истечении окна (`retention_keep_table`
    | в pg_partman); `true` зарезервировано для будущих таблиц, где нужен DETACH, а не DROP.
    |
    | Provider financial facts are permanent and their pinned receipts are not blanket expired.
    |
    */
    'retention' => [
        'prc_fetches' => [
            'months' => (int) env('AI_RETENTION_FETCHES_MONTHS', 6),
            'keep_table' => false,
        ],
        'prc_operation_logs' => [
            'months' => (int) env('AI_RETENTION_OPERATION_LOGS_MONTHS', 6),
            'keep_table' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | «Хранится вечно» (P7.7, D33/D41) — НЕ ТРОГАТЬ retention'ом
    |--------------------------------------------------------------------------
    |
    | Информационный список для теста/аудита (PartitionRetentionTest): доменные/
    | обучающие/аудит-таблицы, которые НИКОГДА не должны попасть в `retention` выше.
    | `prc_estimates` — целиком (в т.ч. `raw_result`, best-effort эвристика footer,
    | P6.2/P6.3); `prc_ai_dataset_examples` — gold-эталоны под обучение (P7.4);
    | `prc_revisions` — история редакций закупок (D41, исключена из `retention`).
    |
    */
    'eternal' => [
        'prc_estimates',
        'prc_ai_dataset_examples',
        'prc_events',
        'prc_revisions',
    ],

    /*
    |--------------------------------------------------------------------------
    | MinIO ILM — lifecycle-правила для request/response-блобов LLM (P7.7, D33)
    |--------------------------------------------------------------------------
    |
    | Cleanup of the retired raw prefix only. Canonical llm/receipts/ objects remain
    | protected by their financial and business provenance.
    |
    */
    'minio_lifecycle' => [
        'llm_call_blobs' => [
            'prefix' => 'ai/llm-calls/',
            'expire_days' => (int) env('AI_STORAGE_LLM_BLOBS_EXPIRE_DAYS', 90),
        ],
    ],
];
