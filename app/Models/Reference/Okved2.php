<?php

declare(strict_types=1);

namespace App\Models\Reference;

use Database\Factories\Reference\Okved2Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Классификатор ОКВЭД2 (иерархия через parent_code, версионность через valid_from/valid_to).
 *
 * @use HasFactory<Okved2Factory>
 */
final class Okved2 extends Model
{
    /** @use HasFactory<Okved2Factory> */
    use HasFactory;

    protected $table = 'ref_okved2';

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
