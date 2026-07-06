<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\StatusMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Маппинг сырого статуса источника на канонический статус ядра.
 *
 * @use HasFactory<StatusMappingFactory>
 */
final class StatusMapping extends Model
{
    /** @use HasFactory<StatusMappingFactory> */
    use HasFactory;

    protected $table = 'prc_status_mappings';

    protected $fillable = [
        'registry_id',
        'source_status',
        'canonical_status',
    ];

    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }
}
