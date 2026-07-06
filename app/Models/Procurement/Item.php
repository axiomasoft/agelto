<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\ItemFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Позиция сметы (строка).
 *
 * @use HasFactory<ItemFactory>
 */
final class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected $table = 'prc_estimate_items';

    protected $fillable = [
        'estimate_id',
        'section_id',
        'row_index',
        'code',
        'name',
        'unit',
        'quantity',
        'unit_price',
        'total_price',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'row_index' => 'integer',
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
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

    public function resources(): HasMany
    {
        return $this->hasMany(related: Resource::class);
    }
}
