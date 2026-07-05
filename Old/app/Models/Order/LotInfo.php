<?php

declare(strict_types=1);

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LotInfo extends Model
{
    protected $table = 'ord_lot_infos';

    protected $fillable = [
        'lot_id',
        'okved_text',
        'okpd2_text',
        'unit',
        'quantity',
        'unit_price',
        'total_price',
        'additional_info',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
