<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Migration;

use App\Dto\Procurement\Network\Control\HttpRouteBinding;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Repositories\Procurement\Process\Support\LedgerRow;

/** Только чтение pre-transfer snapshot identity; не resolver маршрутов и не traffic authority. */
final class HistoricalCatalog
{
    public static function profile(?ProcessUuid $id, string $key): ?LedgerRow
    {
        return $id === null
            ? LedgerRow::one('SELECT id, profile_key, policy_version FROM prc_network_route_profiles WHERE profile_key=?', [$key])
            : LedgerRow::one('SELECT id, profile_key, policy_version FROM prc_network_route_profiles WHERE id=?', [$id->value]);
    }

    /** @return list<HttpRouteBinding> */
    public static function bindings(ProcessUuid $profile): array
    {
        return array_map(static fn (LedgerRow $row): HttpRouteBinding => new HttpRouteBinding(
            new ProcessUuid($row->string('id')), credentialVersion: $row->int('credential_version')),
            LedgerRow::all('SELECT r.id, r.credential_version FROM prc_network_route_profile_members m
                JOIN prc_network_routes r ON r.id=m.route_id WHERE m.profile_id=? ORDER BY r.id', [$profile->value]));
    }
}
