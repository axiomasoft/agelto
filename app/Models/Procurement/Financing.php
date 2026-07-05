<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\FinancingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Годовая разбивка источника финансирования закупки.
 *
 * @use HasFactory<FinancingFactory>
 */
final class Financing extends Model
{
    /** @use HasFactory<FinancingFactory> */
    use HasFactory;

    protected $table = 'prc_financing';

    protected $fillable = [
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
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'is_beyond_horizon' => 'boolean',
            'amount' => 'decimal:2',
        ];
    }
}
