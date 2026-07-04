<?php

declare(strict_types=1);

namespace App\Models\Estimate;

use Database\Factories\Estimate\TotalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Итоговая строка/начисление сметы (иерархия «Итого» → «в т.ч. НДС»).
 *
 * @use HasFactory<TotalFactory>
 */
final class Total extends Model
{
    /** @use HasFactory<TotalFactory> */
    use HasFactory;

    protected $table = 'est_totals';

    protected $fillable = [
        'estimate_id',
        'parent_id',
        'position',
        'label',
        'kind',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(related: Estimate::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(related: self::class, foreignKey: 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(related: self::class, foreignKey: 'parent_id');
    }
}
