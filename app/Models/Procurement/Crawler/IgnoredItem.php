<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Models\Procurement\Platform\Registry;
use Database\Factories\Procurement\Crawler\IgnoredItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denylist конкретных закупок: осознанный skip до создания `prc_procurements`
 * (не ошибка, а решение «не берём»).
 */
#[UseFactory(IgnoredItemFactory::class)]
#[Fillable([
    'registry_id',
    'registry_number',
    'reason_category',
    'reason',
])]
#[Table(name: 'prc_ignored_items')]
final class IgnoredItem extends Model
{
    /** @use HasFactory<IgnoredItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Registry, $this> */
    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }
}
