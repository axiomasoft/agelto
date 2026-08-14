<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\FinancingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Годовая разбивка источника финансирования закупки.
 */
#[UseFactory(FinancingFactory::class)]
#[Fillable([
    'procurement_id',
    'lot_id',
    'kind',
    'kbk',
    'kbk_ref_id',
    'kvr',
    'receipt_code',
    'fiscal_year',
    'is_beyond_horizon',
    'amount',
])]
#[Table(name: 'prc_financing')]
final class Financing extends Model
{
    /** @use HasFactory<FinancingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'is_beyond_horizon' => 'boolean',
            'amount' => 'decimal:2',
        ];
    }
}
