<?php

declare(strict_types=1);
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;

/*
 * Versioned outbound policy v1 (platform-transport#defaults, implementation-blueprint#bp-rate-gcra,
 * #bp-throughput). Стартовые инженерные defaults, не measured SLA и не опубликованная квота
 * источника. Boot-валидируется `OutboundPolicy` без DB/network I/O; неполная конфигурация
 * отвергается с ключом config. Budget default применяется при создании authority row явным
 * `platform:sync-catalog`; последующее изменение — только audited override (sync не затирает).
 *
 * P2.1 вводит только carriers и catalog; старые `crawler.budget.*`/`crawler.proxy.*` остаются
 * у текущих callers до atomic cutover P2.6.
 */
return [
    'policy_version' => 1,

    // Physical origin: 15/min независимо от числа proxy; burst1/inflight2 (D10).
    'origin_budget' => ['rate_per_minute' => 15, 'burst' => 1, 'max_inflight' => 2],

    // Route-origin внутри того же origin limit: 15/min, burst1, inflight1.
    'route_origin_budget' => ['rate_per_minute' => 15, 'burst' => 1, 'max_inflight' => 1],

    // Probe использует те же origin slots; собственный scope ограничивает probe concurrency ≤ 1.
    'probe_budget' => ['rate_per_minute' => 15, 'burst' => 1, 'max_inflight' => 1],

    // Range validation audited override (включительно).
    'override_bounds' => [
        'rate_per_minute' => ['min' => 1, 'max' => 600],
        'burst' => ['min' => 1, 'max' => 10],
        'max_inflight' => ['min' => 1, 'max' => 32],
    ],

    // Bounded FIFO demand tickets + scheduled permits (bp-rate-gcra). status cap — доля
    // CONFIGURED origin hourly capacity, а не доля фактически использованного трафика.
    'scheduling' => [
        'max_inline_wait_seconds' => 20,
        'max_pending_demands_per_origin' => 64,
        'ticket_ttl_seconds' => 5,
        'ticket_renew_seconds' => 1,
        'permit_cleanup_reserve_seconds' => 30,
        'status_capacity_percent' => 30,
    ],

    // Direct route — configured egress identity (общая для workers одной NAT), а не «нет proxy».
    // independence_group null = независимость неизвестна (target circuit по ней не открывается).
    'direct_route' => [
        'egress_id' => env('OUTBOUND_DIRECT_EGRESS_ID', 'default'),
        'independence_group' => env('OUTBOUND_DIRECT_INDEPENDENCE_GROUP'),
    ],

    // Bounded legacy_bridge owner (bp-ddl-network): lease продлевается только пока open/live и не
    // дальше фиксированного deadline; deadline ограничен самым длинным lane (download lease 420 s).
    'legacy_bridge' => [
        'lease_seconds' => 60,
        'max_deadline_seconds' => 420,
    ],

    // Config-only провайдеры source catalog (class strings): actual Definitions подключает
    // P2.9–P2.12. Boot их не вызывает; читает только явный platform:sync-catalog.
    'catalog_providers' => [
        CsvSourceMethod::class,
    ],

    'routing' => ['default_profile' => 'default', 'provider_profile' => 'llm'],

    'http' => [
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'tls_bundles' => ['zakupki.gov.ru' => '/etc/ssl/certs/russian-trusted-ca.pem'],
    ],

    'health' => [
        'global_consecutive' => 5, 'global_base_seconds' => 1800, 'global_cap_seconds' => 21600,
        'route_target_consecutive' => 5, 'route_target_min_sample' => 10, 'route_target_failure_percent' => 60,
        'route_target_window_seconds' => 600, 'route_target_base_seconds' => 300, 'route_target_cap_seconds' => 3600,
        'stable_decay_seconds' => 600, 'corroboration_window_seconds' => 300,
        'origin_cooldown_seconds' => 900, 'probe_reserve_seconds' => 30,
    ],
    'adapter_health' => [
        'policy_version' => 1, 'distinct_inputs' => 3, 'window_seconds' => 300,
        'ramp_successes' => 3, 'normal_concurrency' => 8,
        'input_layout_attempts' => 3, 'input_fault_attempts' => 50,
    ],

];
