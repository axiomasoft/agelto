<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use App\Models\Procurement\Platform\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Logical source target площадки: canonical origin + capability + endpoint policy. Budget
 * принадлежит origin, не target. Retired row хранит историю и не получает новых permits.
 *
 * @property string $id
 * @property int $platform_id
 * @property string $target_key
 * @property string $canonical_origin
 * @property string $capability
 * @property array<string, mixed> $endpoint_policy
 * @property int $endpoint_policy_schema_version
 * @property int $policy_version
 * @property CarbonImmutable|null $retired_at
 * @property-read Platform $platform
 * @property-read TargetHealth|null $health
 */
#[Table(name: 'prc_source_targets', keyType: 'string', incrementing: false, timestamps: false)]
final class SourceTarget extends Model
{
    /** @return BelongsTo<Platform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'platform_id');
    }

    /** @return HasOne<TargetHealth, $this> */
    public function health(): HasOne
    {
        return $this->hasOne(TargetHealth::class, 'target_id');
    }

    protected function casts(): array
    {
        return [
            'endpoint_policy' => 'array',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
