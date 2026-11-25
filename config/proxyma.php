<?php

declare(strict_types=1);

use App\Services\Procurement\Proxyma\PanelProvider;
use App\Support\SecretValue;

return [
    'connection' => env('DB_CONNECTION', 'pgsql'),
    // Точные пары хоста прокси и разрешённых непубличных IP в канонической записи.
    'private_proxies' => [],
    'wire' => ['digest_key' => SecretValue::resolve(name: 'PROXYMA_WIRE_DIGEST_KEY', default: env('PROXYMA_WIRE_DIGEST_KEY'))],
    'scheduler' => ['enabled' => false],
    'extensions' => [
        'approved_providers' => ['agelto' => PanelProvider::class],
        'providers' => ['agelto'],
        'policy_definitions' => [],
        'route_strategies' => [],
        'outcome_policies' => [],
        'route_filters' => [],
        'budget_contributors' => [],
        'locked_admission_guards' => ['agelto.adapter'],
        'observers' => [],
    ],
];
