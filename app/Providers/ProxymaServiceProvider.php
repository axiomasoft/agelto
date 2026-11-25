<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Procurement\Network\ProxymaReconcileCategory;
use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Proxyma\AdapterSendGuard;
use App\Services\Procurement\Proxyma\AgeltoAuthorizer;
use App\Services\Procurement\Proxyma\AgeltoTenantContext;
use App\Services\Procurement\Proxyma\ProcessOwnerAuthority;
use App\Services\Procurement\Proxyma\ProxymaMaintenanceContext;
use App\Services\Procurement\Proxyma\RuntimeCaller;
use App\Services\Procurement\Proxyma\TlsTrustAdapter;
use App\Services\Procurement\Proxyma\UiAuthority;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Filament\ProxymaFilamentServiceProvider;

final class ProxymaServiceProvider extends ServiceProvider
{
    private const array MAINTENANCE_COMMANDS = [
        'proxyma:reconcile' => ProxymaMaintenanceContext::RECONCILE,
        'proxyma:prune' => ProxymaMaintenanceContext::PRUNE,
    ];

    public function register(): void
    {
        $this->app->register(InactiveProxymaServiceProvider::class);
        $this->app->scoped(ProcessOwnerAuthority::class);
        $this->app->scoped(AdapterSendGuard::class);
        $this->app->bind(OwnerAuthority::class, fn () => $this->app->make(ProcessOwnerAuthority::class));
        $this->app->bind(TlsTrustPolicy::class, TlsTrustAdapter::class);
        $this->app->scoped(RuntimeCaller::class);
        $this->app->scoped(ProxymaMaintenanceContext::class);
        $this->app->bind(Authorizer::class, AgeltoAuthorizer::class);
        $this->app->bind(TenantContextResolver::class, AgeltoTenantContext::class);
        $this->app->bind(CatalogMutationFence::class, UiAuthority::class);
        $this->app->register(ProxymaFilamentServiceProvider::class);

        $this->app->afterResolving(ReconcileDaemon::class, function (ReconcileDaemon $daemon): void {
            $daemon->register($this->app->make(ProxymaReconcileCategory::class));
        });
    }

    public function boot(Dispatcher $events): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            $ability = self::MAINTENANCE_COMMANDS[$event->command] ?? null;
            if ($ability !== null) {
                $this->app->make(ProxymaMaintenanceContext::class)->begin(ability: $ability, owner: $event->input);
            }
        });
        $events->listen(CommandFinished::class, function (CommandFinished $event): void {
            if (isset(self::MAINTENANCE_COMMANDS[$event->command])) {
                $this->app->make(ProxymaMaintenanceContext::class)->end($event->input);
            }
        });
    }
}
