<?php

declare(strict_types=1);

namespace App\Models\Procurement\Platform;

use Database\Factories\Procurement\Platform\Okpd2NodeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Узел карты «код ОКПД2 → внутренний id справочника НСИ» конкретной площадки (D19): свой
 * носитель площадочного `external_id`, отдельный от общего классификатора `ref_okpd2`.
 */
#[UseFactory(Okpd2NodeFactory::class)]
final class Okpd2Node extends Model
{
    use HasFactory;

    protected $table = 'prc_okpd2_nodes';

    protected $fillable = [
        'platform_id',
        'code',
        'external_id',
        'parent_code',
        'level',
        'name',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
