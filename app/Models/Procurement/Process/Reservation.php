<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Member admission group, PRIMARY KEY (admission_group_id, lane_id). Released group не имеет
 * members (DELETE в той же tx); независимых TTL нет. Read model без Eloquent writes.
 *
 * @property string $admission_group_id
 * @property string $lane_id
 * @property string $work_id
 * @property StageCode $stage
 * @property string $state
 * @property-read AdmissionGroup $group
 * @property-read Lane $lane
 */
#[Table(name: 'prc_process_reservations', key: 'admission_group_id', keyType: 'string', incrementing: false, timestamps: false)]
final class Reservation extends Model
{
    /** @return BelongsTo<AdmissionGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AdmissionGroup::class, 'admission_group_id');
    }

    /** @return BelongsTo<Lane, $this> */
    public function lane(): BelongsTo
    {
        return $this->belongsTo(Lane::class, 'lane_id');
    }

    protected function casts(): array
    {
        return ['stage' => StageCode::class];
    }
}
