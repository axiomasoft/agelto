<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Enums\Procurement\Crawler\SourceStageStatus;
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
 * Исторический снимок прежнего этапа (площадка × этап); текущим сетевым допуском не управляет.
 * Сохранённые прежние значения по
 * умолчанию — `config('crawler.source_control')`; здесь только данные и производный
 * `status()`. После ожидания этап просто работает дальше (пробных запросов нет); остановка
 * из-за структуры и ручная пауза снимаются только оператором.
 *
 * @property int $id
 * @property int $platform_id
 * @property SourceStage $stage
 * @property bool $enabled
 * @property bool $manually_paused
 * @property int|null $failure_threshold переопределение конфига; null — из `crawler.source_control`
 * @property int|null $layout_threshold
 * @property int|null $retry_interval_minutes
 * @property int $consecutive_failures
 * @property int $consecutive_layout_failures
 * @property int $consecutive_skips
 * @property Carbon|null $retry_at
 * @property Carbon|null $held_at
 * @property SourceFailureKind|null $last_failure_kind
 * @property Carbon|null $last_failure_at
 * @property string|null $changed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Platform|null $platform
 */
#[UseFactory(SourceCircuitFactory::class)]
#[Fillable([
    'platform_id', 'stage', 'enabled', 'manually_paused', 'failure_threshold', 'layout_threshold',
    'retry_interval_minutes', 'consecutive_failures', 'consecutive_layout_failures', 'consecutive_skips', 'retry_at', 'held_at', 'last_failure_kind', 'last_failure_at', 'changed_by',
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
            'layout_threshold' => 'integer',
            'consecutive_failures' => 'integer',
            'consecutive_layout_failures' => 'integer',
            'consecutive_skips' => 'integer',
            'retry_at' => 'datetime',
            'held_at' => 'datetime',
            'last_failure_kind' => SourceFailureKind::class,
            'last_failure_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Platform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }

    /** Состояние этапа; приоритет — пауза оператора, остановка, ожидание. */
    public function status(): SourceStageStatus
    {
        return match (true) {
            $this->manually_paused => SourceStageStatus::Paused,
            $this->held_at !== null => SourceStageStatus::Held,
            $this->retry_at !== null && $this->retry_at->isFuture() => SourceStageStatus::Waiting,
            default => SourceStageStatus::Running,
        };
    }

    /** Можно ли сейчас ходить в источник на этом этапе. */
    public function admits(): bool
    {
        return $this->status()->admits();
    }
}
