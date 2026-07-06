<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\ProcurementRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Снапшот редакции закупки (партиция `prc_revisions` RANGE by `detected_at`).
 */
#[UseFactory(ProcurementRevisionFactory::class)]
final class ProcurementRevision extends Model
{
    use HasFactory;

    protected $table = 'prc_revisions';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'procurement_id',
        'revision_number',
        'source_edition',
        'change_reason',
        'snapshot',
        'snapshot_hash',
        'source_updated_at',
        'detected_at',
        'crawler_run_id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'source_updated_at' => 'datetime',
            'detected_at' => 'datetime',
        ];
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(related: Procurement::class);
    }
}
