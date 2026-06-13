<?php

declare(strict_types=1);

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Lot extends Model
{
    protected $table = 'ord_lots';

    protected $fillable = [
        'order_id',
        'lot_number',
        'subject',
        'okved',
        'okpd2',
        'is_centralized',
        'initial_price',
        'dirty_data',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function infos(): HasMany
    {
        return $this->hasMany(LotInfo::class);
    }

    protected function casts(): array
    {
        return [
            'lot_number' => 'integer',
            'is_centralized' => 'boolean',
            'initial_price' => 'decimal:2',
            'dirty_data' => 'array',
        ];
    }
}
