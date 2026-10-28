<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writer `prc_network_routes`. P2.1 материализует только configured direct egress identity;
 * proxy routes (unique FK из `prc_proxies`) и route profiles переключает P2.6. Наличие route row
 * не даёт права слать: разрешение задаёт route profile membership. Существующую row (её
 * administrative enabled/disabled) ensure не меняет.
 */
final readonly class RouteRepository
{
    public function ensureDirectRoute(string $egressId, ?string $independenceGroup): string
    {
        CallerTransaction::require('Direct route ensure');
        $key = 'direct:'.$egressId;
        DB::insert(
            "INSERT INTO prc_network_routes (id, route_key, kind, egress_id, independence_group, enabled, credential_version)
             VALUES (?, ?, 'direct', ?, ?, true, 1) ON CONFLICT (route_key) DO NOTHING",
            [Str::lower((string) Str::uuid()), $key, $egressId, $independenceGroup],
        );
        $row = LedgerRow::one('SELECT id, kind, egress_id FROM prc_network_routes WHERE route_key = ?', [$key])
            ?? throw new \LogicException('Direct route disappeared: routes are never deleted.');
        if ($row->string('kind') !== 'direct' || $row->string('egress_id') !== $egressId) {
            throw new \LogicException('Route identity is immutable: '.$key);
        }

        return $row->string('id');
    }
}
