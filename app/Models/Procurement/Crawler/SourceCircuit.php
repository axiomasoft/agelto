<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Enums\Procurement\Crawler\SourceCircuitState;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\SourceControl\SourceCircuitSnapshot;
use Carbon\CarbonImmutable;
use Database\Factories\Procurement\Crawler\SourceCircuitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Брейкер источника (площадки). Пишет только `SourceCircuitController` (под блокировкой
 * строки); пороги `enabled`/`failure_threshold`/`retry_interval_minutes` редактирует оператор.
 *
 * @property int $id
 * @property int $platform_id
 * @property SourceCircuitState $state
 * @property bool $enabled
 * @property bool $manually_paused
 * @property int $failure_threshold
 * @property int $retry_interval_minutes
 * @property int $consecutive_failures
 * @property int $open_count
 * @property Carbon|null $opened_at
 * @property Carbon|null $retry_at
 * @property ListingFailureKind|null $last_failure_kind
 * @property Carbon|null $last_failure_at
 * @property string|null $changed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Platform|null $platform
 */
#[UseFactory(SourceCircuitFactory::class)]
#[Fillable([
    'platform_id', 'state', 'enabled', 'manually_paused', 'failure_threshold', 'retry_interval_minutes',
    'consecutive_failures', 'open_count', 'opened_at', 'retry_at', 'last_failure_kind', 'last_failure_at', 'changed_by',
])]
#[Table(name: 'prc_source_circuits')]
final class SourceCircuit extends Model
{
    /** @use HasFactory<SourceCircuitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'state' => SourceCircuitState::class,
            'enabled' => 'boolean',
            'manually_paused' => 'boolean',
            'failure_threshold' => 'integer',
            'retry_interval_minutes' => 'integer',
            'consecutive_failures' => 'integer',
            'open_count' => 'integer',
            'opened_at' => 'datetime',
            'retry_at' => 'datetime',
            'last_failure_kind' => ListingFailureKind::class,
            'last_failure_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Platform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }

    public function toSnapshot(): SourceCircuitSnapshot
    {
        return new SourceCircuitSnapshot(
            state: $this->state,
            enabled: $this->enabled,
            manuallyPaused: $this->manually_paused,
            failureThreshold: $this->failure_threshold,
            retryIntervalMinutes: $this->retry_interval_minutes,
            consecutiveFailures: $this->consecutive_failures,
            openCount: $this->open_count,
            openedAt: $this->opened_at === null ? null : CarbonImmutable::instance($this->opened_at),
            retryAt: $this->retry_at === null ? null : CarbonImmutable::instance($this->retry_at),
            lastFailureKind: $this->last_failure_kind,
            lastFailureAt: $this->last_failure_at === null ? null : CarbonImmutable::instance($this->last_failure_at),
        );
    }

    public function fillFromSnapshot(SourceCircuitSnapshot $snapshot): self
    {
        $this->forceFill([
            'state' => $snapshot->state,
            'manually_paused' => $snapshot->manuallyPaused,
            'consecutive_failures' => $snapshot->consecutiveFailures,
            'open_count' => $snapshot->openCount,
            'opened_at' => $snapshot->openedAt,
            'retry_at' => $snapshot->retryAt,
            'last_failure_kind' => $snapshot->lastFailureKind,
            'last_failure_at' => $snapshot->lastFailureAt,
        ]);

        return $this;
    }
}
