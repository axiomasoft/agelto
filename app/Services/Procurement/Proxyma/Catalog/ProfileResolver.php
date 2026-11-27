<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Catalog;

use App\Dto\Procurement\Network\Control\HttpRouteBinding;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Models\Procurement\Proxyma\ProfileLink;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Proxyma\Migration\HistoricalCatalog;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Читает identity перенесённого профиля для consumer snapshot. Selection выполняет package. */
final readonly class ProfileResolver
{
    public function __construct(private OutboundPolicy $policy) {}

    public function profile(?ProcessUuid $id = null, bool $provider = false): ProfileBindings
    {
        if (DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->value('authority') !== 'proxyma') {
            $historical = HistoricalCatalog::profile($id,
                $provider ? $this->policy->providerRouteProfile : $this->policy->defaultRouteProfile);
            if ($historical === null) {
                throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
            }
            $source = new ProcessUuid($historical->string('id'));

            return new ProfileBindings(HistoricalCatalog::bindings($source), $source);
        }
        $tables = PackageSchema::tables();
        $query = DB::table((new ProfileLink)->getTable().' as l')
            ->join($tables['route_profiles'].' as p', 'p.id', '=', 'l.profile_id')
            ->where('l.tenant_key', TenantKey::SYSTEM)->where('p.tenant_key', TenantKey::SYSTEM)->whereNull('p.retired_at');
        $id === null
            ? $query->where('p.profile_key', $provider ? $this->policy->providerRouteProfile : $this->policy->defaultRouteProfile)
            : $query->where('l.source_profile_id', $id->value);
        $profile = $query->orderByDesc('l.source_version')->first(['l.source_profile_id', 'l.profile_id']);
        if ($profile === null || ! is_string($profile->source_profile_id) || ! is_string($profile->profile_id)) {
            throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
        }
        $bindings = [];
        /** @var object{id: string} $route */
        foreach (DB::table($tables['route_profile_members'].' as m')->join($tables['routes'].' as r', 'r.id', '=', 'm.route_id')
            ->where('m.profile_id', $profile->profile_id)->where('m.tenant_key', TenantKey::SYSTEM)
            ->where('r.tenant_key', TenantKey::SYSTEM)->whereNull('r.retired_at')->orderBy('r.id')
            ->get(['r.id']) as $route) {
            $bindings[] = new HttpRouteBinding(new ProcessUuid($route->id));
        }

        return new ProfileBindings($bindings, new ProcessUuid($profile->source_profile_id));
    }

    public function outboundRoutes(ProfileBindings $policy): ProfileBindings
    {
        return $policy;
    }

    /** Историческая identity snapshot; current membership/policy применяет package executor. */
    public function snapshot(?ProcessUuid $id = null, ?string $key = null): ?LedgerRow
    {
        if (DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->value('authority') !== 'proxyma') {
            return HistoricalCatalog::profile($id, $key ?? $this->policy->defaultRouteProfile);
        }
        $links = (new ProfileLink)->getTable();
        $tables = PackageSchema::tables();
        $condition = $id === null ? 'p.profile_key = ?' : 'l.source_profile_id = ?::uuid';

        return LedgerRow::one("SELECT l.source_profile_id AS id, p.profile_key, l.source_version AS policy_version
            FROM {$links} l JOIN {$tables['route_profiles']} p ON p.id=l.profile_id AND p.tenant_key=l.tenant_key
            WHERE l.tenant_key=? AND p.retired_at IS NULL AND {$condition}
            ORDER BY l.source_version DESC LIMIT 1", [TenantKey::SYSTEM, $id === null ? ($key ?? $this->policy->defaultRouteProfile) : $id->value]);
    }
}
