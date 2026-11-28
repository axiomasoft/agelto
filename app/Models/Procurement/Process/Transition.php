<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Устойчивое событие work/run/control/incident, записанное в той же tx, что изменение.
 * Ровно один entity FK соответствует entity_kind; unique(entity, entity_seq) — partial indexes.
 *
 * @property string $event_id
 * @property string $entity_kind
 * @property string|null $work_id
 * @property string|null $run_id
 * @property string|null $control_id
 * @property string|null $incident_id
 * @property int $entity_seq
 * @property string|null $previous_state
 * @property string $new_state
 * @property string|null $reason_code
 * @property string $actor_kind
 * @property int|null $actor_id
 * @property int|null $generation
 * @property array<string, mixed>|null $details
 * @property int|null $details_schema_version
 * @property CarbonImmutable $occurred_at
 * @property int $schema_version
 */
#[Table(name: 'prc_process_transitions', key: 'event_id', keyType: 'string', incrementing: false, timestamps: false)]
final class Transition extends Model
{
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
