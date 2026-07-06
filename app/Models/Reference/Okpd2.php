<?php

declare(strict_types=1);

namespace App\Models\Reference;

use Database\Factories\Reference\Okpd2Factory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Классификатор ОКПД2 (иерархия через parent_code, версионность через valid_from/valid_to).
 */
#[UseFactory(Okpd2Factory::class)]
final class Okpd2 extends Model
{
    use HasFactory;

    protected $table = 'ref_okpd2';

    protected $fillable = [
        'code',
        'parent_code',
        'level',
        'name',
        'valid_from',
        'valid_to',
        'attributes',
    ];

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
