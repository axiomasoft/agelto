<?php

declare(strict_types=1);

namespace App\Models\Procurement\Estimate;

use App\Enums\Procurement\Estimate\ResourceType;
use Database\Factories\Procurement\Estimate\ResourceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ресурс позиции сметы (трудозатраты/машины/материалы/накладные/прочее).
 *
 * @property ResourceType $resource_type
 * @property string|null $code
 * @property string $name
 * @property string|null $unit
 * @property string|null $quantity
 * @property string|null $price_base
 * @property string|null $price_curr
 * @property string|null $index_code
 * @property string|null $index_value
 */
#[UseFactory(ResourceFactory::class)]
final class Resource extends Model
{
    use HasFactory;

    protected $table = 'prc_estimate_resources';

    protected $fillable = [
        'item_id',
        'resource_type',
        'code',
        'name',
        'unit',
        'unit_base',
        'unit_multiplier',
        'quantity',
        'price_base',
        'price_curr',
        'index_code',
        'index_value',
    ];

    protected function casts(): array
    {
        return [
            'resource_type' => ResourceType::class,
            'unit_multiplier' => 'decimal:6',
            'quantity' => 'decimal:6',
            'price_base' => 'decimal:2',
            'price_curr' => 'decimal:2',
            'index_value' => 'decimal:6',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(related: Item::class);
    }
}
