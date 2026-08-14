<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Enums\Procurement\AwardStatus;
use Database\Factories\Procurement\AwardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Итог по лоту (OCDS awards) — денормализованная витрина результата закупки.
 */
#[UseFactory(AwardFactory::class)]
#[Fillable([
    'uuid',
    'procurement_id',
    'lot_id',
    'winner_org_id',
    'final_price',
    'currency',
    'status',
    'contract_registry_number',
    'awarded_at',
    'payload',
])]
#[Table(name: 'prc_awards')]
final class Award extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'final_price' => 'decimal:2',
            'status' => AwardStatus::class,
            'awarded_at' => 'datetime',
            'payload' => AsCollection::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $award): void {
            $award->uuid ??= (string) Str::uuid7();
        });
    }
}
