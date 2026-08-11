<?php

namespace App\Providers\Filament;

use App\Guards\Admin\Permissions\DocumentationPermission;
use AzGuard\Filament\AzGuardPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
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
                AzGuardPlugin::make()->forPanel('admin'),
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
            // Явная группировка и порядок навигации (канон из v1 Old-панели): решение о
            // том, как ресурсы сгруппированы, живёт здесь, ресурсы лишь ссылаются на группу.
            ->navigationGroups([
                NavigationGroup::make('Краулинг'),
                NavigationGroup::make('Закупки'),
                NavigationGroup::make('AI-анализ'),
                NavigationGroup::make('Доступ'),
            ])
            ->navigationItems([
                NavigationItem::make('Документация')
                    ->group('Доступ')
                    ->url(fn (): string => route(name: 'admin.documentation.show'))
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
