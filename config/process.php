<?php

declare(strict_types=1);

/*
 * Versioned process policy v1 (execution#lane-defaults, #handler-fault-budget). Стартовая
 * конфигурация, а не measured SLA; boot-валидируется `ProcessPolicy`. Набор lanes каждой stage
 * (required_lanes) — конечная typed таблица `ProcessPolicy`, здесь только числа. Число worker
 * processes authority не является: W/C считает ledger по active reservation rows.
 */
return [
    'policy_version' => 1,

    // W = max_outstanding (admitted+running), C = max_running; W20 → low10/refill≤10, W10 → low5/refill≤5.
    // Parent и child limits действуют одновременно (analysis — общий parent screening/extraction).
    'lanes' => [
        'acquisition' => ['parent' => null, 'max_outstanding' => 20, 'max_running' => 4, 'low_watermark' => 10, 'refill_max' => 10],
        'acquisition.listing' => ['parent' => 'acquisition', 'max_outstanding' => 10, 'max_running' => 2, 'low_watermark' => 5, 'refill_max' => 5],
        'acquisition.detail' => ['parent' => 'acquisition', 'max_outstanding' => 20, 'max_running' => 4, 'low_watermark' => 10, 'refill_max' => 10],
        'acquisition.status_refresh' => ['parent' => 'acquisition', 'max_outstanding' => 10, 'max_running' => 2, 'low_watermark' => 5, 'refill_max' => 5],
        'download' => ['parent' => null, 'max_outstanding' => 20, 'max_running' => 4, 'low_watermark' => 10, 'refill_max' => 10],
        'prepare' => ['parent' => null, 'max_outstanding' => 10, 'max_running' => 2, 'low_watermark' => 5, 'refill_max' => 5],
        'analysis' => ['parent' => null, 'max_outstanding' => 20, 'max_running' => 10, 'low_watermark' => 10, 'refill_max' => 10],
        'analysis.screening' => ['parent' => 'analysis', 'max_outstanding' => 20, 'max_running' => 10, 'low_watermark' => 10, 'refill_max' => 10],
        'analysis.extraction' => ['parent' => 'analysis', 'max_outstanding' => 20, 'max_running' => 10, 'low_watermark' => 10, 'refill_max' => 10],
        'publication' => ['parent' => null, 'max_outstanding' => 10, 'max_running' => 2, 'low_watermark' => 5, 'refill_max' => 5],
    ],

    // EXR-02: конечный budget unexpected handler faults на (work, handler_version); fault 3 → blocked(handler_fault_limit).
    'max_handler_faults_per_work_version' => 3,
];
