<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Партиционированные таблицы (RANGE by time, помесячно)
    |--------------------------------------------------------------------------
    |
    | Единый список для pg_partman part_config (миграция) и для fallback-команды
    | `partitions:maintain` — список таблиц задаётся здесь.
    |
    */
    'tables' => [
        'prc_events' => 'occurred_at',
        'prc_revisions' => 'detected_at',
    ],

    'interval' => '1 month',

    'premake' => 3,

    /*
    |--------------------------------------------------------------------------
    | Retention — DROP старых партиций per-таблица
    |--------------------------------------------------------------------------
    |
    | DROP-retention сейчас выключен: текущие таблицы хранят доменную историю.
    | Список `eternal` ниже запрещает применять к ним удаление старых партиций.
    |
    | `months` — окно хранения партиции (мигрирует в `partman.part_config.retention`
    | как `"<months> months"`, миграция `2026_07_09_100050_set_pg_partman_retention`).
    | `keep_table` — `false` = DROP партиции по истечении окна (`retention_keep_table`
    | в pg_partman); `true` зарезервировано для будущих таблиц, где нужен DETACH, а не DROP.
    |
    | Provider financial facts are permanent and their pinned receipts are not blanket expired.
    |
    */
    'retention' => [],

    /*
    |--------------------------------------------------------------------------
    | «Хранится вечно» — НЕ ТРОГАТЬ retention'ом
    |--------------------------------------------------------------------------
    |
    | Информационный список для теста/аудита (PartitionRetentionTest): доменные/
    | обучающие/аудит-таблицы, которые НИКОГДА не должны попасть в `retention` выше.
    | `prc_estimates` — принятые сметы; `prc_ai_dataset_examples` — gold-эталоны;
    | `prc_revisions` — история редакций закупок (исключена из `retention`).
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
    | MinIO ILM — lifecycle-правила для request/response-блобов LLM
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
