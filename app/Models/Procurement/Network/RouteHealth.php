<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use App\Enums\Procurement\Network\CircuitState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Route-global health (proven proxy-phase failures). Единственный writer — network health
 * reducer (P2.4); epoch/probe fencing под lock.
 *
 * @property string $id
 * @property string $route_id
 * @property CircuitState $state
 * @property int $health_epoch
 * @property int $state_version
 * @property string|null $reason_code
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_failure_at
 * @property CarbonImmutable|null $open_until
 * @property string|null $probe_token
 * @property CarbonImmutable|null $probe_until
 * @property CarbonImmutable|null $stable_since
 * @property int $recent_openings
 * @property int $consecutive_failures
 */
#[Table(name: 'prc_route_health', keyType: 'string', incrementing: false, timestamps: false)]
final class RouteHealth extends Model
{
    protected function casts(): array
    {
        return [
            'state' => CircuitState::class,
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'open_until' => 'immutable_datetime',
            'probe_until' => 'immutable_datetime',
            'stable_since' => 'immutable_datetime',
        ];
    }
}
