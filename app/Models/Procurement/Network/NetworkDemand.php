<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use App\Enums\Procurement\Network\DemandPurpose;
use App\Enums\Procurement\Network\DemandState;
use App\Enums\Procurement\Network\TrafficClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Bounded FIFO demand ticket одного physical request. Owner — ровно один из (work, attempt,
 * generation) или (outbound operation, generation): CHECK all-or-none.
 *
 * @property string $id
 * @property string $physical_request_id
 * @property string|null $work_id
 * @property string|null $attempt_id
 * @property int|null $work_generation
 * @property string|null $operation_id
 * @property int|null $operation_generation
 * @property string $origin_budget_id
 * @property string $route_id
 * @property string $target_id
 * @property TrafficClass $traffic_class
 * @property DemandPurpose $purpose
 * @property int $ticket_seq
 * @property CarbonImmutable $registered_at
 * @property CarbonImmutable $renewed_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $operation_deadline
 * @property DemandState $state
 */
#[Table(name: 'prc_network_demands', keyType: 'string', incrementing: false, timestamps: false)]
final class NetworkDemand extends Model
{
    protected function casts(): array
    {
        return [
            'traffic_class' => TrafficClass::class,
            'purpose' => DemandPurpose::class,
            'state' => DemandState::class,
            'registered_at' => 'immutable_datetime',
            'renewed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'operation_deadline' => 'immutable_datetime',
        ];
    }
}
