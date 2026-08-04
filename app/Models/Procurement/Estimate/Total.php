<?php

declare(strict_types=1);

namespace App\Models\Procurement\Estimate;

use App\Enums\Procurement\Estimate\TotalKind;
use Database\Factories\Procurement\Estimate\TotalFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Итоговая строка/начисление сметы (иерархия «Итого» → «в т.ч. НДС»).
 *
 * @property string $label
 * @property TotalKind|null $kind
 * @property string|null $amount
 * @property int|null $chapter_number
 * @property string|null $rate
 */
#[UseFactory(TotalFactory::class)]
final class Total extends Model
{
    use HasFactory;

    protected $table = 'prc_estimate_totals';

    protected $fillable = [
        'estimate_id',
        'parent_id',
        'position',
        'label',
        'kind',
        'amount',
        'chapter_number',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => TotalKind::class,
            'amount' => 'decimal:2',
            'chapter_number' => 'integer',
            'rate' => 'decimal:6',
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
