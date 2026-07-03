<?php

declare(strict_types=1);

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseObject extends Model
{
    protected $table = 'ord_purchase_objects';

    protected $fillable = [
        'order_id',
        'okpd2',
        'name',
        'quantity',
        'unit',
        'price',
        'total_cost',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'price' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }
}
