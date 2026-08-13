<?php

declare(strict_types=1);

use App\Filament\Pages\CrawlerProxySettingsPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\PromptCompositionPage;
use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\DatasetExampleResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Ai\LlmRoutes\LlmRouteResource;
use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\PromptSuggestionResource;
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
use Filament\Navigation\NavigationGroup;

test('назначает всем обнаруженным ресурсам осмысленные русские navigation labels', function () {
    $panel = Filament::getPanel('admin');
    $resources = collect($panel->getResources())
        ->filter(static fn (string $resource): bool => str_starts_with($resource, 'App\\Filament\\Resources\\'))
        ->values();

    $expected = [
        ProcurementResource::class => ['Закупки', 'Закупки', 10],
        EstimateResource::class => ['Закупки', 'Сметы', 20],
        AnalysisResource::class => ['Закупки', 'Результаты анализа', 30],
        PlatformResource::class => ['Площадки', 'Площадки', 10],
        RegistryResource::class => ['Площадки', 'Реестры закупок', 20],
        StatusMappingResource::class => ['Площадки', 'Соответствие статусов', 30],
        PlatformCheckResource::class => ['Площадки', 'Проверки площадок', 40],
        CrawlerResource::class => ['Сбор данных', 'Задания сбора', 10],
        RunResource::class => ['Сбор данных', 'Запуски сбора', 20],
        ProxyResource::class => ['Сбор данных', 'Прокси-серверы', 30],
        FixtureResource::class => ['Качество анализа', 'Эталонные документы', 10],
        DatasetExampleResource::class => ['Качество анализа', 'Проверенные примеры', 20],
        ProviderResource::class => ['Настройка AI', 'AI-провайдеры', 10],
        LlmRouteResource::class => ['Настройка AI', 'Модели для задач', 20],
        PromptResource::class => ['Настройка AI', 'Инструкции для AI', 30],
        PromptSuggestionResource::class => ['Настройка AI', 'Предложения по улучшению', 50],
        OperationLogResource::class => ['Система', 'Журнал процессов', 10],
    ];

    expect($resources->all())->toHaveCount(count($expected));

    foreach ($resources as $resource) {
        expect($expected)->toHaveKey($resource)
            ->and($resource::getNavigationGroup())->toBe($expected[$resource][0])
            ->and($resource::getNavigationLabel())->toBe($expected[$resource][1])
            ->and($resource::getNavigationSort())->toBe($expected[$resource][2])
            ->and($resource::getModelLabel())->not->toBe('')
            ->and($resource::getPluralModelLabel())->not->toBe('');
    }
});

test('фиксирует точный порядок групп и application pages', function (): void {
    $groups = array_map(
        static fn (NavigationGroup|string $group): ?string => $group instanceof NavigationGroup
            ? $group->getLabel()
            : $group,
        Filament::getPanel('admin')->getNavigationGroups(),
    );

    expect($groups)->toBe([
        'Закупки',
        'Площадки',
        'Сбор данных',
        'Качество анализа',
        'Настройка AI',
        'Доступ и безопасность',
        'Система',
    ])->and(Dashboard::getNavigationLabel())->toBe('Обзор')
        ->and(Dashboard::getNavigationSort())->toBe(-100)
        ->and(CrawlerProxySettingsPage::getNavigationGroup())->toBe('Сбор данных')
        ->and(CrawlerProxySettingsPage::getNavigationLabel())->toBe('Прокси по умолчанию')
        ->and(CrawlerProxySettingsPage::getNavigationSort())->toBe(40)
        ->and(PromptCompositionPage::getNavigationGroup())->toBe('Настройка AI')
        ->and(PromptCompositionPage::getNavigationLabel())->toBe('Сборка инструкции')
        ->and(PromptCompositionPage::getNavigationSort())->toBe(40);
});
