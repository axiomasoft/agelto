<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capacity lane: W = max_outstanding (admitted+running), C = max_running. Authoritative count —
 * active reservation rows под lane lock, не counter cache (execution#s05/#s11).
 *
 * @property string $id
 * @property string $lane_key
 * @property string|null $parent_lane_id
 * @property int $max_outstanding
 * @property int $max_running
 * @property int $low_watermark
 * @property int $refill_max
 * @property int $policy_version
 * @property array<string, mixed> $fairness_cursor
 * @property int $fairness_cursor_schema_version
 * @property-read Lane|null $parent
 */
#[Table(name: 'prc_process_lanes', keyType: 'string', incrementing: false, timestamps: false)]
final class Lane extends Model
{
    /** @return BelongsTo<Lane, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_lane_id');
    }

    protected function casts(): array
    {
        return ['fairness_cursor' => 'array'];
    }
}
