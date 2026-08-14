<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\ClarificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Разъяснение документации закупки. Не двигает revision_number закупки.
 */
#[UseFactory(ClarificationFactory::class)]
#[Fillable([
    'procurement_id',
    'requested_at',
    'answered_at',
    'question',
    'answer',
    'document_version_id',
])]
#[Table(name: 'prc_clarifications')]
final class Clarification extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'requested_at' => 'date',
            'answered_at' => 'date',
        ];
    }
}
