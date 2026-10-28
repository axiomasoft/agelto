<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\OutboundOperationState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Standalone outbound owner (legacy_bridge/diagnostic/probe). policy_hash — bytea evidence
 * политики на момент open; controls snapshot — `prc_outbound_operation_controls`.
 *
 * @property string $id
 * @property string $operation_key
 * @property OutboundOperationKind $kind
 * @property int|null $platform_id
 * @property string $caller_code
 * @property OutboundOperationState $state
 * @property string $owner_token
 * @property int $generation
 * @property CarbonImmutable $lease_until
 * @property CarbonImmutable $deadline_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $probe_token
 */
#[Table(name: 'prc_outbound_operations', keyType: 'string', incrementing: false, timestamps: false)]
final class OutboundOperation extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => OutboundOperationKind::class,
            'state' => OutboundOperationState::class,
            'lease_until' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
