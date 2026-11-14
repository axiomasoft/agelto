<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use App\Guards\Admin\Permissions\ProxyPermission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\Authorizer;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

final readonly class UiAuthorizer implements Authorizer
{
    public function __construct(private UiAuthority $authority) {}

    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
    {
        $user = Auth::user();
        if (! $tenant->isSystem() || ! $user instanceof User || $actor->type !== 'operator'
            || $actor->id !== (string) $user->id || ! $this->authority->active('proxyma')) {
            throw new AuthorizationDenied;
        }
        $permission = match ($ability) {
            'catalog.endpoint.list', 'catalog.route.list', 'catalog.profile.list', 'catalog.target.list',
            'operations.budgets.list', 'operations.health.list', 'operations.attempts.list', 'operations.audit.list',
            'operations.bindings.list', 'operations.policy.list' => ProxyPermission::ViewAny,
            'catalog.endpoint.read', 'catalog.route.read', 'catalog.profile.read', 'catalog.target.read',
            'catalog.route.select', 'catalog.endpoint.select', 'catalog.profile.select', 'binding.view', 'policy.view', 'policy.inspect', 'policy.resolve',
            'operations.budgets.read', 'operations.health.read', 'operations.attempts.read', 'operations.audit.read',
            'operations.bindings.read', 'operations.policy.read' => ProxyPermission::View,
            'catalog.endpoint.upsert', 'catalog.route.upsert', 'catalog.profile.upsert' => $this->upsertPermission($ability, $tenant, $resourceId),
            'catalog.endpoint.rotate-credentials', 'catalog.route.set-enabled', 'binding.update',
            'policy.update', 'policy.scope.entity.edit', 'policy.scope.tenant.edit', 'policy.scope.global.edit', 'policy.scope.panel.edit',
            'policy.timeout.edit', 'policy.retry.edit', 'policy.http.edit', 'policy.route.edit', 'policy.destination.edit',
            'health.reset' => ProxyPermission::Update,
            'catalog.endpoint.retire', 'catalog.route.retire', 'catalog.profile.retire' => ProxyPermission::Delete,
            default => null,
        };
        if ($permission === null || ! $user->hasPermission(permission: $permission, panelId: 'admin')) {
            throw new AuthorizationDenied;
        }
    }

    private function upsertPermission(string $ability, TenantKey $tenant, Uuid $id): ProxyPermission
    {
        $table = match ($ability) {
            'catalog.endpoint.upsert' => 'endpoints', 'catalog.route.upsert' => 'routes', default => 'route_profiles',
        };
        $exists = DB::table(PackageSchema::tables()[$table])
            ->where('tenant_key', $tenant->value)->where('id', $id->value)->exists();

        return $exists ? ProxyPermission::Update : ProxyPermission::Create;
    }
}
