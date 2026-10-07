<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Platform\Platform;
use Database\Factories\Procurement\Crawler\SourceCircuitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Брейкер этапа источника. Состояний два, отдельного поля нет: «ждёт», пока `retry_at` в
 * будущем, иначе «работает». После ожидания этап просто работает дальше — пробных запросов
 * (half-open) нет: если источник всё ещё лежит, через `failure_threshold` отказов этап снова
 * уйдёт в ожидание. Ручная пауза — отдельный флаг, снимается только оператором.
 *
 * @property int $id
 * @property int $platform_id
 * @property SourceStage $stage
 * @property bool $enabled
 * @property bool $manually_paused
 * @property int $failure_threshold
 * @property int $retry_interval_minutes
 * @property int $consecutive_failures
 * @property Carbon|null $retry_at
 * @property SourceFailureKind|null $last_failure_kind
 * @property Carbon|null $last_failure_at
 * @property string|null $changed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Platform|null $platform
 */
#[UseFactory(SourceCircuitFactory::class)]
#[Fillable([
    'platform_id', 'stage', 'enabled', 'manually_paused', 'failure_threshold', 'retry_interval_minutes',
    'consecutive_failures', 'retry_at', 'last_failure_kind', 'last_failure_at', 'changed_by',
])]
#[Table(name: 'prc_source_circuits')]
final class SourceCircuit extends Model
{
    /** @use HasFactory<SourceCircuitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'stage' => SourceStage::class,
            'enabled' => 'boolean',
            'manually_paused' => 'boolean',
            'failure_threshold' => 'integer',
            'retry_interval_minutes' => 'integer',
            'consecutive_failures' => 'integer',
            'retry_at' => 'datetime',
            'last_failure_kind' => SourceFailureKind::class,
            'last_failure_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Platform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }

    /** Ждёт после серии отказов (не путать с ручной паузой). */
    public function isWaiting(): bool
    {
        return $this->retry_at !== null && $this->retry_at->isFuture();
    }

    /** Можно ли сейчас ходить в источник на этом этапе. */
    public function admits(): bool
    {
        return ! $this->manually_paused && ! ($this->enabled && $this->isWaiting());
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->manually_paused && $this->changed_by === 'system' => 'Остановлен: изменилась структура',
            $this->manually_paused => 'Пауза оператора',
            $this->enabled && $this->isWaiting() => 'Ожидание',
            default => 'Работает',
        };
    }
}
