<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\ClarificationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Разъяснение документации закупки. Не двигает revision_number закупки.
 */
#[UseFactory(ClarificationFactory::class)]
final class Clarification extends Model
{
    use HasFactory;

    protected $table = 'prc_clarifications';

    protected $fillable = [
        'procurement_id',
        'requested_at',
        'answered_at',
        'question',
        'answer',
        'document_version_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'date',
            'answered_at' => 'date',
        ];
    }
}
