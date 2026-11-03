<?php

declare(strict_types=1);

/*
 * Versioned process policy v1 (execution#lane-defaults, #handler-fault-budget). Стартовая
 * конфигурация, а не measured SLA; boot-валидируется `ProcessPolicy`. Набор lanes каждой stage
 * (required_lanes) — конечная typed таблица `ProcessPolicy`, здесь только числа. Число worker
 * processes authority не является: W/C считает ledger по active reservation rows.
 */
return [
    'diagnostic_thresholds' => [
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

    'diagnostics' => [
        'baseline_path' => 'resources/crawler/health/eis-baseline.json',
        'platform_baselines' => ['eis' => 'resources/crawler/health/eis-baseline.json'],
        'window_hours' => 24,
        'repeat_alert_hours' => 24,
    ],

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

    // StageQueueMap (bp-jobs): connection, queue и job timeout каждой stage. Horizon timeout и
    // retry_after берутся из фактических config/horizon.php и config/queue.php, `StageQueueMap`
    // проверяет slice < job timeout < Horizon timeout < retry_after < lease. Redis default
    // connection и supervisor-1 остаются для не-Process jobs (notifications/mail).
    'queues' => [
        'listing' => ['connection' => 'process_long', 'queue' => 'process-listing', 'job_timeout' => 260],
        'detail' => ['connection' => 'process_short', 'queue' => 'process-acquisition', 'job_timeout' => 110],
        'status_refresh' => ['connection' => 'process_short', 'queue' => 'process-acquisition', 'job_timeout' => 110],
        'download' => ['connection' => 'process_download', 'queue' => 'process-download', 'job_timeout' => 320],
        'prepare' => ['connection' => 'process_long', 'queue' => 'process-prepare', 'job_timeout' => 260],
        'screening' => ['connection' => 'process_ai', 'queue' => 'process-ai', 'job_timeout' => 290],
        'extraction' => ['connection' => 'process_ai', 'queue' => 'process-ai', 'job_timeout' => 290],
        'publication' => ['connection' => 'process_short', 'queue' => 'process-publication', 'job_timeout' => 110],
    ],

    // Process daemons (bp-daemons): отдельные relay/reconcile/feed процессы, не scheduler. Liveness —
    // heartbeat каждые heartbeat_seconds; instance жив, пока last_tick_at свежее live_seconds.
    // Relay claim-ит только work_wake, feed — только feeder_wake; reconcile tick 5 s ±20%.
    'daemons' => [
        'heartbeat_seconds' => 10,
        'live_seconds' => 30,
        'max_runtime_seconds' => 3600,
        'memory_limit_mb' => 256,
        'error_backoff_min_seconds' => 1,
        'error_backoff_max_seconds' => 30,
        'relay' => ['batch' => 100, 'claim_seconds' => 30, 'idle_min_ms' => 250, 'idle_max_ms' => 500],
        'reconcile' => ['tick_seconds' => 5, 'jitter_percent' => 20],
        'feed' => ['tick_seconds' => 2, 'jitter_percent' => 20, 'poll_ms' => 500, 'wake_batch' => 100, 'claim_seconds' => 30],
        // Projector claim-ит только kind projection; tick 1 s (bp-daemons), batch ограничивает claim.
        'project' => ['tick_seconds' => 1, 'batch' => 100, 'claim_seconds' => 30],
    ],

    // Projection (observability#s08): bounded maintenance rebuild — не SLA для большого архива.
    // rebuild_max_events/deadline ограничивают одну REPEATABLE READ транзакцию; превышение — отказ
    // без смены generation. Quarantined/maintenance сообщения откладываются, а не теряются.
    'projection' => [
        'rebuild_max_events' => 100000,
        'rebuild_deadline_seconds' => 30,
        'rebuild_page' => 1000,
        'quarantine_retry_seconds' => 3600,
        'maintenance_defer_seconds' => 5,
        'prune_batch' => 500,
    ],

    // Retention history (observability#s07): начальные проектные defaults, не нормативные сроки.
    // Transitions и operator audit хранятся минимум 365 дней (boot отвергает меньше); активные
    // incidents, нетерминальные work/runs и последнее состояние каждой сущности не удаляются.
    'retention' => [
        'transition_days' => 365,
        'audit_days' => 365,
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
