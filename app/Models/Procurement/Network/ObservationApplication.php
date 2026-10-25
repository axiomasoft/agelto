<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dedup receipt reducer: PRIMARY KEY (observation_id, projection_key), пишется в одной tx с
 * projection. Read model без собственного key.
 *
 * @property string $observation_id
 * @property string $projection_key
 * @property int $reducer_version
 * @property CarbonImmutable $applied_at
 * @property-read Observation $observation
 */
#[Table(name: 'prc_observation_applications', key: 'observation_id', keyType: 'string', incrementing: false, timestamps: false)]
final class ObservationApplication extends Model
{
    /** @return BelongsTo<Observation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class, 'observation_id');
    }

    protected function casts(): array
    {
        return ['applied_at' => 'immutable_datetime'];
    }
}
