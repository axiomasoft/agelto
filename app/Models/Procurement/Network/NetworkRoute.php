<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use App\Enums\Procurement\Network\RouteKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Egress route identity (direct либо proxy) + administrative enabled + secret ref. Writer —
 * `Repositories/Procurement/Network`; read model без собственного health state.
 *
 * @property string $id
 * @property string $route_key
 * @property RouteKind $kind
 * @property string $egress_id
 * @property string|null $independence_group
 * @property bool $enabled
 * @property CarbonImmutable|null $disabled_at
 * @property string|null $disabled_reason
 * @property string|null $secret_ref
 * @property int $credential_version
 * @property-read RouteHealth|null $health
 */
#[Table(name: 'prc_network_routes', keyType: 'string', incrementing: false, timestamps: false)]
final class NetworkRoute extends Model
{
    /** @return HasOne<RouteHealth, $this> */
    public function health(): HasOne
    {
        return $this->hasOne(RouteHealth::class, 'route_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => RouteKind::class,
            'enabled' => 'boolean',
            'disabled_at' => 'immutable_datetime',
        ];
    }
}
