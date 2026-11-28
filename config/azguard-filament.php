<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Proxyma\Filament\Pages\AttemptsPage;
use Proxyma\Filament\Pages\AuditPage;
use Proxyma\Filament\Pages\BindingsPage;
use Proxyma\Filament\Pages\BudgetsPage;
use Proxyma\Filament\Pages\HealthPage;
use Proxyma\Filament\Pages\PolicyInspector;
use Proxyma\Filament\Resources\Pages\Endpoints;
use Proxyma\Filament\Resources\Pages\RouteProfiles;
use Proxyma\Filament\Resources\Pages\Routes;

return [

    'guard_panel' => 'admin',

    'manages' => null,

    'enforce' => true,

    'definitions' => 'enums',

    'authority' => 'grants',

    'abilities' => [
        'view_any',
        'view',
        'create',
        'update',
        'delete',
        'delete_any',
        'force_delete',
        'force_delete_any',
        'restore',
        'restore_any',
        'replicate',
        'reorder',
    ],

    'exclude' => [
        'resources' => [
            // Авторизация канонических промтов зависит от поля is_protected.
            PromptResource::class,
        ],
        'pages' => [
            CreateRecord::class,
            // Proxyma проверяет чтение и мутации через собственный UiAuthorizer.
            Endpoints::class,
            Routes::class,
            RouteProfiles::class,
            BindingsPage::class,
            PolicyInspector::class,
            BudgetsPage::class,
            HealthPage::class,
            AttemptsPage::class,
            AuditPage::class,
        ],
        'widgets' => [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ],
    ],

];
