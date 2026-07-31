<?php

declare(strict_types=1);

namespace App\Models\Procurement\Estimate;

use Database\Factories\Procurement\Estimate\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Позиция сметы (строка).
 *
 * @property int|null $section_id
 * @property string|null $code
 * @property string $name
 * @property string|null $unit
 * @property string|null $quantity
 * @property string|null $unit_price_base
 * @property string|null $unit_price_curr
 * @property string|null $total_price_base
 * @property string|null $total_price_curr
 * @property string|null $index_code
 * @property string|null $index_value
 * @property string|null $source_position_number
 */
#[UseFactory(ItemFactory::class)]
final class Item extends Model
{
    use HasFactory;

    protected $table = 'prc_estimate_items';

    protected $fillable = [
        'estimate_id',
        'section_id',
        'row_index',
        'code',
        'name',
        'unit',
        'unit_base',
        'unit_multiplier',
        'quantity',
        'unit_price_base',
        'unit_price_curr',
        'total_price_base',
        'total_price_curr',
        'index_code',
        'index_value',
        'source_position_number',
        'work_type_id',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'row_index' => 'integer',
            'unit_multiplier' => 'decimal:6',
            'quantity' => 'decimal:6',
            'unit_price_base' => 'decimal:2',
            'unit_price_curr' => 'decimal:2',
            'total_price_base' => 'decimal:2',
            'total_price_curr' => 'decimal:2',
            'index_value' => 'decimal:6',
            'attributes' => AsCollection::class,
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(related: Estimate::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(related: Section::class);
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(related: WorkType::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(related: Resource::class);
    }
}
