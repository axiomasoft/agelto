<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\IgnoredItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denylist конкретных закупок: осознанный skip до создания `prc_procurements`
 * (не ошибка, а решение «не берём»).
 *
 * @use HasFactory<IgnoredItemFactory>
 */
final class IgnoredItem extends Model
{
    /** @use HasFactory<IgnoredItemFactory> */
    use HasFactory;

    protected $table = 'prc_ignored_items';

    protected $fillable = [
        'registry_id',
        'source_number',
        'reason_category',
        'reason',
    ];

    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }
}
