<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Guards\Admin\Permissions\DocumentationPermission;
use AzGuard\Filament\AzGuardPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Proxyma\Filament\ProxymaPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->plugins([
                AzGuardPlugin::make()->guardPanel('admin'),
                ProxymaPlugin::make(),
            ])
            // Ресурсы раскладываются по доменным подпапкам (зеркало app/Models/**);
            // discoverResources рекурсивен — подхватывает вложенные namespace.
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
                // Стабильный порядок навигации; ресурсы используют только эти группы.
            ->navigationGroups([
                NavigationGroup::make('Закупки'),
                NavigationGroup::make('Площадки'),
                NavigationGroup::make('Сбор данных'),
                NavigationGroup::make('Качество анализа'),
                NavigationGroup::make('Настройка AI'),
                NavigationGroup::make('Доступ и безопасность'),
                NavigationGroup::make('Система'),
            ])
            ->navigationItems([
                NavigationItem::make('Документация')
                    ->group('Система')
                    ->sort(20)
                    ->url(fn (): string => route(name: 'documentation.show'))
                    ->visible(fn (): bool => auth()->user()?->hasPermission(
                        permission: DocumentationPermission::View,
                        panelId: 'admin',
                    ) ?? false),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
