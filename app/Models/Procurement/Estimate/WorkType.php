<?php

declare(strict_types=1);

namespace App\Models\Procurement\Estimate;

use Database\Factories\Procurement\Estimate\WorkTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Вид работ и его иерархия — per-estimate носитель нормативов НР/СП (D34/D90).
 */
#[UseFactory(WorkTypeFactory::class)]
#[Fillable([
    'estimate_id',
    'parent_id',
    'position',
    'title',
    'overhead_code',
    'overhead_rate',
    'overhead_rate_curr',
    'profit_code',
    'profit_rate',
    'profit_rate_curr',
])]
#[Table(name: 'prc_estimate_work_types')]
final class WorkType extends Model
{
    /** @use HasFactory<WorkTypeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'overhead_rate' => 'decimal:6',
            'overhead_rate_curr' => 'decimal:6',
            'profit_rate' => 'decimal:6',
            'profit_rate_curr' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(related: Estimate::class);
    }

    /** @return BelongsTo<WorkType, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(related: self::class, foreignKey: 'parent_id');
    }

    /** @return HasMany<WorkType, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(related: self::class, foreignKey: 'parent_id');
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(related: Item::class);
    }
}
