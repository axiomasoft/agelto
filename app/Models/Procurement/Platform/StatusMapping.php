<?php

declare(strict_types=1);

namespace App\Models\Procurement\Platform;

use Database\Factories\Procurement\Platform\StatusMappingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Маппинг сырого статуса источника на канонический статус ядра.
 */
#[UseFactory(StatusMappingFactory::class)]
final class StatusMapping extends Model
{
    use HasFactory;

    protected $table = 'prc_status_mappings';

    protected $fillable = [
        'registry_id',
        'source_stage',
        'canonical_status',
    ];

    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }
}
