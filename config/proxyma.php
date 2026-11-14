<?php

declare(strict_types=1);

use App\Services\Procurement\Proxyma\PanelProvider;

return [
    'connection' => env('DB_CONNECTION', 'pgsql'),
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
