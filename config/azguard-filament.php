<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

return [

    'guard_panel' => 'admin',

    'manages' => null,

    'enforce' => false,

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
            PromptResource::class,
            AnalysisResource::class,
        ],
        'pages' => [
            CreateRecord::class,
        ],
        'widgets' => [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ],
    ],

];
