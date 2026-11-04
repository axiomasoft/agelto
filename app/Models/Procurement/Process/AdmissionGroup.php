<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * work + dispatch_generation + fixed required lane set. Один active group на work (partial unique);
 * members вставляются/переводятся/освобождаются только целиком.
 *
 * @property string $id
 * @property string $work_id
 * @property int $dispatch_generation
 * @property mixed $required_lane_set_hash
 * @property string|null $attempt_id
 * @property string $state
 * @property int $version
 * @property CarbonImmutable $admitted_at
 * @property CarbonImmutable|null $released_at
 * @property string|null $release_reason
 * @property-read WorkItem $work
 */
#[Table(name: 'prc_admission_groups', keyType: 'string', incrementing: false, timestamps: false)]
final class AdmissionGroup extends Model
{
    /** @return BelongsTo<WorkItem, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_id');
    }

    /** @return HasMany<Reservation, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Reservation::class, 'admission_group_id');
    }

    protected function casts(): array
    {
        return [
            'admitted_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }
}
