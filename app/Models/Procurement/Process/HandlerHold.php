<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use App\Enums\Procurement\Process\HandlerEligibility;
use App\Enums\Procurement\Process\StageCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materialized handler-version eligibility authority unique(stage, handler_version): healthy
 * создаётся registration до первого admission; held требует signature/incident/held_at. Не
 * подменяет manual desired_mode.
 *
 * @property string $id
 * @property StageCode $stage
 * @property string $handler_version
 * @property HandlerEligibility $state
 * @property int $version
 * @property mixed $fault_signature
 * @property string|null $incident_id
 * @property CarbonImmutable|null $held_at
 * @property-read Incident|null $incident
 */
#[Table(name: 'prc_process_handler_holds', keyType: 'string', incrementing: false, timestamps: false)]
final class HandlerHold extends Model
{
    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    protected function casts(): array
    {
        return [
            'stage' => StageCode::class,
            'state' => HandlerEligibility::class,
            'held_at' => 'immutable_datetime',
        ];
    }
}
