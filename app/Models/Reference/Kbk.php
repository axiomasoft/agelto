<?php

declare(strict_types=1);

namespace App\Models\Reference;

use Database\Factories\Reference\KbkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Классификатор КБК (иерархия через parent_code, версионность через valid_from/valid_to).
 */
#[UseFactory(KbkFactory::class)]
#[Fillable([
    'code',
    'parent_code',
    'level',
    'name',
    'valid_from',
    'valid_to',
    'attributes',
])]
#[Table(name: 'ref_kbk')]
final class Kbk extends Model
{
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
