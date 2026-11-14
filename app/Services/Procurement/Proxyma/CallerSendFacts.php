<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Models\Procurement\Proxyma\ProfileLink;
use App\Models\Procurement\Proxyma\TargetLink;
use App\Repositories\Procurement\Process\LedgerRow;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Consumer projections читают package facts, не создавая второй attempt journal. */
final class CallerSendFacts
{
    public static function active(): bool
    {
        return DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->value('authority') === 'proxyma';
    }

    /** @return array{routes: ProfileBindings, profile: string|null} */
    public static function probeRoutes(ProfileBindings $routes, OutboundRequestPlan $plan, ExecutionContext $context): array
    {
        if ($routes->profileId === null) {
            return ['routes' => $routes, 'profile' => null];
        }

        return app(RuntimeCaller::class)->run($plan, $context, function () use ($routes, $plan, $context): array {
            $target = DB::table((new TargetLink)->getTable())->where('tenant_key', TenantKey::SYSTEM)
                ->where('source_target_id', $plan->targetId->value)->value('target_id');
            if (! is_string($target)) {
                throw new AdmissionRejected('target_missing');
            }
            $settings = CallerPolicyContext::resolve($plan, $context);
            $policy = app(PolicyResolver::class)->resolveEffective($settings->subjects, new Uuid($target), $settings->slot, panelKey: $settings->panel, bypassCache: true);
            $profile = $policy->routeProfileId ?? app(BindingAdapter::class)->activeProfile($routes->profileId)->id;
            $bindings = [];
            foreach (app(CatalogQueries::class)->routesForProfile(TenantKey::system(), $profile) as $route) {
                if ($route->enabled && ! $route->isRetired()) {
                    $bindings[] = new HttpRouteBinding(new ProcessUuid($route->id->value), credentialVersion: $route->credentialVersion);
                }
            }

            return ['routes' => new ProfileBindings($bindings, $routes->profileId, $routes->random), 'profile' => $profile->value];
        });
    }

    /** @phpstan-impure Читает изменяемый SQL health после каждого физического send. */
    public static function needsProbe(ProfileBindings $routes, string $sourceTarget, ?string $profile = null): bool
    {
        $t = PackageSchema::tables();
        $target = DB::table((new TargetLink)->getTable())->where('tenant_key', TenantKey::SYSTEM)->where('source_target_id', $sourceTarget)->value('target_id');
        if ($target === null) {
            return false;
        }
        if ($routes->profileId !== null) {
            $profile ??= DB::table((new ProfileLink)->getTable())->where('tenant_key', TenantKey::SYSTEM)
                ->where('source_profile_id', $routes->profileId->value)->orderByDesc('source_version')->value('profile_id');
            if ($profile !== null && DB::table($t['profile_health'])->where('tenant_key', TenantKey::SYSTEM)
                ->where('profile_id', $profile)->where('state', '!=', 'closed')->exists()) {
                return true;
            }
        }
        if (DB::table($t['target_health'])->where('tenant_key', TenantKey::SYSTEM)->where('target_id', $target)->where('state', '!=', 'closed')->exists()) {
            return true;
        }
        foreach ($routes->bindings as $binding) {
            if (DB::table($t['route_health'])->where('tenant_key', TenantKey::SYSTEM)->where('route_id', $binding->id->value)->where('state', 'closed')->exists()
                && DB::table($t['route_target_health'])->where('tenant_key', TenantKey::SYSTEM)->where('route_id', $binding->id->value)
                    ->where('target_id', $target)->where('state', 'closed')->exists()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<LedgerRow> */
    public static function probeCandidates(?string $after, int $limit): array
    {
        $t = PackageSchema::tables();
        $due = "((h.state='open' AND h.open_until <= clock_timestamp()) OR (h.state='half_open' AND h.probe_until <= clock_timestamp()))";

        return LedgerRow::all("SELECT s.*, s.endpoint_policy->>'probe_url' AS probe_url FROM prc_source_targets s
            JOIN prc_proxyma_target_links l ON l.source_target_id=s.id AND l.tenant_key=?
            WHERE s.retired_at IS NULL AND s.endpoint_policy->>'probe_url' IS NOT NULL AND (?::uuid IS NULL OR s.id > ?::uuid)
            AND (EXISTS (SELECT 1 FROM {$t['target_health']} h WHERE h.target_id=l.target_id AND h.tenant_key=l.tenant_key AND {$due})
              OR EXISTS (SELECT 1 FROM {$t['route_target_health']} h WHERE h.target_id=l.target_id AND h.tenant_key=l.tenant_key AND {$due})
              OR EXISTS (SELECT 1 FROM {$t['route_health']} h JOIN {$t['route_target_health']} p ON p.route_id=h.route_id AND p.tenant_key=h.tenant_key
                  WHERE p.target_id=l.target_id AND h.tenant_key=l.tenant_key AND {$due}))
            ORDER BY s.id LIMIT ?", [TenantKey::SYSTEM, $after, $after, max(1, min(64, $limit))]);
    }

    public static function completed(string $physical, string $ownerType, string $owner, int $generation): bool
    {
        $t = PackageSchema::tables();
        if (! DB::getSchemaBuilder()->hasTable($t['attempts'])) {
            return false;
        }

        return DB::table($t['attempts'].' as a')->join($t['operations'].' as o', 'o.id', '=', 'a.operation_id')
            ->where('a.tenant_key', TenantKey::SYSTEM)->where('o.tenant_key', TenantKey::SYSTEM)
            ->where('a.physical_attempt_id', $physical)->where('a.status', 'completed')->whereNotNull('a.receipt_id')
            ->where('o.owner_type', $ownerType)->where('o.owner_id', $owner)->where('o.owner_generation', $generation)->exists();
    }

    public static function operationSends(string $owner): int
    {
        $t = PackageSchema::tables();
        if (! DB::getSchemaBuilder()->hasTable($t['attempts'])) {
            return 0;
        }

        return DB::table($t['attempts'].' as a')->join($t['operations'].' as o', 'o.id', '=', 'a.operation_id')
            ->where('a.tenant_key', TenantKey::SYSTEM)->where('o.tenant_key', TenantKey::SYSTEM)
            ->where('o.owner_type', 'outbound_operation')->where('o.owner_id', $owner)->count();
    }
}
