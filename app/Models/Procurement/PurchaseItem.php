<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\PurchaseItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Позиция объекта закупки (OCDS tender.items).
 */
#[UseFactory(PurchaseItemFactory::class)]
final class PurchaseItem extends Model
{
    use HasFactory;

    protected $table = 'prc_items';

    protected $fillable = [
        'procurement_id',
        'lot_id',
        'position_number',
        'name',
        'characteristics',
        'classifier_scheme',
        'classifier_code',
        'okpd2_ref_id',
        'ktru_ref_id',
        'quantity',
        'unit',
        'unit_price',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'position_number' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }
}
