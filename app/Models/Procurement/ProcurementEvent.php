<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Enums\Procurement\ProcurementEventType;
use Database\Factories\Procurement\ProcurementEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Типизированное событие в журнале закупки (партиция `prc_events` RANGE by `occurred_at`).
 *
 * @use HasFactory<ProcurementEventFactory>
 */
final class ProcurementEvent extends Model
{
    /** @use HasFactory<ProcurementEventFactory> */
    use HasFactory;

    protected $table = 'prc_events';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'procurement_id',
        'revision_id',
        'event_type',
        'entity_ref',
        'field',
        'old_value',
        'new_value',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ProcurementEventType::class,
            'entity_ref' => 'array',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(related: Procurement::class);
    }
}
