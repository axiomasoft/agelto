<?php

declare(strict_types=1);

namespace App\Models\Reference;

use Database\Factories\Reference\KtruFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Классификатор КТРУ (подмножество ОКПД2 — `okpd2_code` держит родительский код ОКПД2).
 */
#[UseFactory(KtruFactory::class)]
#[Fillable([
    'code',
    'parent_code',
    'level',
    'name',
    'okpd2_code',
    'valid_from',
    'valid_to',
    'attributes',
])]
#[Table(name: 'ref_ktru')]
final class Ktru extends Model
{
    /** @use HasFactory<KtruFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'attributes' => 'array',
        ];
    }
}
