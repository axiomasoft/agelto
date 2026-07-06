<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Enums\Procurement\ResourceType;
use Database\Factories\Procurement\ResourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ресурс позиции сметы (трудозатраты/машины/материалы/накладные/прочее).
 *
 * @use HasFactory<ResourceFactory>
 */
final class Resource extends Model
{
    /** @use HasFactory<ResourceFactory> */
    use HasFactory;

    protected $table = 'prc_estimate_resources';

    protected $fillable = [
        'item_id',
        'resource_type',
        'code',
        'name',
        'unit',
        'quantity',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'resource_type' => ResourceType::class,
            'quantity' => 'decimal:6',
            'price' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(related: Item::class);
    }
}
