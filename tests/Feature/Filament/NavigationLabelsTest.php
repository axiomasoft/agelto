<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\DatasetExampleResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Ai\LlmRoutes\LlmRouteResource;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\PromptSuggestionResource;
use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use App\Filament\Resources\Procurement\Ai\Providers\ProviderResource;
use App\Filament\Resources\Procurement\Crawler\Crawlers\CrawlerResource;
use App\Filament\Resources\Procurement\Crawler\Proxies\ProxyResource;
use App\Filament\Resources\Procurement\Crawler\Runs\RunResource;
use App\Filament\Resources\Procurement\Estimates\EstimateResource;
use App\Filament\Resources\Procurement\Health\PlatformChecks\PlatformCheckResource;
use App\Filament\Resources\Procurement\OperationLogs\OperationLogResource;
use App\Filament\Resources\Procurement\Platform\Platforms\PlatformResource;
use App\Filament\Resources\Procurement\Platform\Registries\RegistryResource;
use App\Filament\Resources\Procurement\Platform\StatusMappings\StatusMappingResource;
use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use Filament\Facades\Filament;

test('назначает всем обнаруженным ресурсам осмысленные русские navigation labels', function () {
    $panel = Filament::getPanel('admin');
    $resources = collect($panel->getResources())
        ->filter(static fn (string $resource): bool => str_starts_with($resource, 'App\\Filament\\Resources\\'))
        ->values();

    $expected = [
        ProcurementResource::class => ['Закупки', 'Закупки'],
        EstimateResource::class => ['Закупки', 'Сметы'],
        AnalysisResource::class => ['Закупки', 'Результаты анализа'],
        PlatformResource::class => ['Площадки', 'Площадки'],
        RegistryResource::class => ['Площадки', 'Реестры закупок'],
        StatusMappingResource::class => ['Площадки', 'Соответствие статусов'],
        PlatformCheckResource::class => ['Площадки', 'Проверки площадок'],
        CrawlerResource::class => ['Сбор данных', 'Задания сбора'],
        RunResource::class => ['Сбор данных', 'Запуски сбора'],
        ProxyResource::class => ['Сбор данных', 'Прокси-серверы'],
        FixtureResource::class => ['Качество анализа', 'Эталонные документы'],
        DatasetExampleResource::class => ['Качество анализа', 'Проверенные примеры'],
        ProviderResource::class => ['Настройка AI', 'AI-провайдеры'],
        LlmRouteResource::class => ['Настройка AI', 'Модели для задач'],
        PromptResource::class => ['Настройка AI', 'Инструкции для AI'],
        PromptSuggestionResource::class => ['Настройка AI', 'Предложения по улучшению'],
        OperationLogResource::class => ['Система', 'Журнал процессов'],
    ];

    expect($resources->all())->toHaveCount(count($expected));

    foreach ($resources as $resource) {
        expect($expected)->toHaveKey($resource)
            ->and($resource::getNavigationGroup())->toBe($expected[$resource][0])
            ->and($resource::getNavigationLabel())->toBe($expected[$resource][1])
            ->and($resource::getModelLabel())->not->toBe('')
            ->and($resource::getPluralModelLabel())->not->toBe('');
    }
});
