<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\CancellationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Решение об отмене процедуры. `reverted_at` — отмена отмены (возобновление процедуры).
 */
#[UseFactory(CancellationFactory::class)]
#[Fillable([
    'procurement_id',
    'decision_at',
    'reason',
    'document_version_id',
    'reverted_at',
])]
#[Table(name: 'prc_cancellations')]
final class Cancellation extends Model
{
    /** @use HasFactory<CancellationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'decision_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }
}
