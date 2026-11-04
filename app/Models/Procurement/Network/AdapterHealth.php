<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use App\Enums\Procurement\Network\AdapterHealthState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Health (platform, capability, adapter_version, contract_version). Transport success не снимает
 * adapter hold; rows создаёт Definition sync.
 *
 * @property string $id
 * @property int $platform_id
 * @property string $capability
 * @property string $adapter_version
 * @property string $contract_version
 * @property AdapterHealthState $state
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
 */
#[Table(name: 'prc_adapter_health', keyType: 'string', incrementing: false, timestamps: false)]
final class AdapterHealth extends Model
{
    protected function casts(): array
    {
        return [
            'state' => AdapterHealthState::class,
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'open_until' => 'immutable_datetime',
            'probe_until' => 'immutable_datetime',
            'stable_since' => 'immutable_datetime',
        ];
    }
}
