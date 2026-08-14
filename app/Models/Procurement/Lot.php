<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Enums\Procurement\ProcurementStatus;
use Database\Factories\Procurement\LotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Лот закупки (223-ФЗ — реальный лот через lot_guid; 44-ФЗ — виртуальный лот №1).
 */
#[UseFactory(LotFactory::class)]
#[Fillable([
    'procurement_id',
    'lot_number',
    'lot_guid',
    'subject',
    'status',
    'is_centralized',
    'initial_price',
    'currency',
    'okpd2_code',
    'okved_code',
    'attributes',
])]
#[Table(name: 'prc_lots')]
final class Lot extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ProcurementStatus::class,
            'is_centralized' => 'boolean',
            'initial_price' => 'decimal:2',
            'attributes' => AsCollection::class,
        ];
    }
}
