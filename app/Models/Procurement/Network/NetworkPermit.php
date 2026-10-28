<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use App\Enums\Procurement\Network\PermitState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Scheduled permit: owner/target/class/request_id берутся через immutable demand FK, а не
 * дублируются. Scope set — `prc_network_permit_scopes` (composition child).
 *
 * @property string $id
 * @property string $demand_id
 * @property CarbonImmutable $slot_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $authorized_at
 * @property PermitState $state
 * @property array<string, int> $policy_versions
 * @property int $policy_schema_version
 * @property array<string, int> $health_epochs
 * @property int $health_epochs_schema_version
 * @property CarbonImmutable $created_at
 */
#[Table(name: 'prc_network_permits', keyType: 'string', incrementing: false, timestamps: false)]
final class NetworkPermit extends Model
{
    protected function casts(): array
    {
        return [
            'state' => PermitState::class,
            'policy_versions' => 'array',
            'health_epochs' => 'array',
            'slot_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
