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

    // Admission (bp-sql-admit): age900 — порог aged work (по immutable created_at); candidate_limit —
    // bounded выборка внутри уже выбранных cohort/stage; CapacityWait claim откладывает wake на 5–10 s.
    'admission' => [
        'age_seconds' => 900,
        'candidate_limit' => 5,
        'capacity_wait_min_ms' => 5000,
        'capacity_wait_max_ms' => 10000,
    ],

    // Delivery deadline admitted work (claim visibility, transport, не lease), monotonic slice
    // исполнения и DB lease claimed attempt в секундах (execution#lane-defaults, bp-jobs).
    // StageQueueMap (P1.10) берёт те же bounds для job/Horizon timeout и retry_after.
    'stages' => [
        'listing' => ['claim_visibility' => 300, 'slice' => 240, 'lease' => 360],
        'detail' => ['claim_visibility' => 120, 'slice' => 90, 'lease' => 180],
        'status_refresh' => ['claim_visibility' => 120, 'slice' => 90, 'lease' => 180],
        'download' => ['claim_visibility' => 300, 'slice' => 300, 'lease' => 420],
        'prepare' => ['claim_visibility' => 300, 'slice' => 240, 'lease' => 360],
        'screening' => ['claim_visibility' => 300, 'slice' => 270, 'lease' => 390],
        'extraction' => ['claim_visibility' => 300, 'slice' => 270, 'lease' => 390],
        'publication' => ['claim_visibility' => 120, 'slice' => 90, 'lease' => 180],
    ],

    // Кооперативный heartbeat (bp-heartbeat): CAS не чаще heartbeat_seconds по monotonic clock;
    // finalization reserve входит в каждый canStart; локальный safety deadline = последний
    // успешный renewal + lease − lease_safety. Boot требует lease > slice и lease > 2·heartbeat + safety.
    'execution' => [
        'heartbeat_seconds' => 15,
        'finalization_reserve_seconds' => 15,
        'lease_safety_seconds' => 30,
    ],

    // EXR-02: конечный budget unexpected handler faults на (work, handler_version); fault 3 → blocked(handler_fault_limit).
    'max_handler_faults_per_work_version' => 3,
];
