<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Platform\Filament\Resources\Crawler\CrawlerResource;
use Platform\Filament\Resources\DocumentAnalysis\DocumentAnalysisResource;
use Platform\Filament\Resources\Link\LinkResource;
use Platform\Filament\Resources\Platform\PlatformResource;
use Platform\Filament\Resources\Promt\PromtResource;
use Sys\Filament\Resources\Proxy\ProxyResource;
use Sys\Filament\Resources\Service\ServiceResource;

final class AdminPanelProvider extends PanelProvider
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
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(in: base_path('Modules/Platform/app/Filament/Resources'), for: 'Platform\Filament\Resources')
            ->discoverResources(in: base_path('Modules/Sys/app/Filament/Resources'), for: 'Sys\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->navigation(builder: function (NavigationBuilder $builder): NavigationBuilder {
                return $builder->groups([
                    NavigationGroup::make(label: 'Платформы')
                        ->icon(icon: 'heroicon-s-square-3-stack-3d')
                        ->items(items: [
                            ...PlatformResource::getNavigationItems(),
                            ...CrawlerResource::getNavigationItems(),
                            ...LinkResource::getNavigationItems(),
                            ...DocumentAnalysisResource::getNavigationItems(),
                            ...PromtResource::getNavigationItems(),
                        ]),
                    NavigationGroup::make(label: 'Система')
                        ->icon(icon: 'heroicon-s-cog-6-tooth')
                        ->items(items: [
                            ...ServiceResource::getNavigationItems(),
                            ...ProxyResource::getNavigationItems(),
                        ]),
                ]);
            });
    }
}
