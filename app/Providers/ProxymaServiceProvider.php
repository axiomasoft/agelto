<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Procurement\Proxyma\AdapterSendGuard;
use App\Services\Procurement\Proxyma\AgeltoAuthorizer;
use App\Services\Procurement\Proxyma\AgeltoTenantContext;
use App\Services\Procurement\Proxyma\ProcessOwnerAuthority;
use App\Services\Procurement\Proxyma\RuntimeCaller;
use App\Services\Procurement\Proxyma\TlsTrustAdapter;
use App\Services\Procurement\Proxyma\UiAuthority;
use Illuminate\Support\ServiceProvider;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Filament\ProxymaFilamentServiceProvider;

final class ProxymaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(InactiveProxymaServiceProvider::class);
        $this->app->scoped(ProcessOwnerAuthority::class);
        $this->app->scoped(AdapterSendGuard::class);
        $this->app->bind(OwnerAuthority::class, fn () => $this->app->make(ProcessOwnerAuthority::class));
        $this->app->bind(TlsTrustPolicy::class, TlsTrustAdapter::class);
        $this->app->scoped(RuntimeCaller::class);
        $this->app->bind(Authorizer::class, AgeltoAuthorizer::class);
        $this->app->bind(TenantContextResolver::class, AgeltoTenantContext::class);
        $this->app->bind(CatalogMutationFence::class, UiAuthority::class);
        $this->app->register(ProxymaFilamentServiceProvider::class);
    }
}
