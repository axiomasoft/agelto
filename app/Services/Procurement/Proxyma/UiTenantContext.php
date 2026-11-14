<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;

final class UiTenantContext implements TenantContextResolver
{
    public function resolve(): TenantContext
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new AuthorizationDenied;
        }

        return new TenantContext(TenantKey::system(), new ActorRef('operator', (string) $user->id));
    }
}
