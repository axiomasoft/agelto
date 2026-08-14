<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\ProcurementRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Снапшот редакции закупки (партиция `prc_revisions` RANGE by `detected_at`).
 *
 * @property int $id
 * @property int $procurement_id
 * @property int $revision_number
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_hash
 */
#[UseFactory(ProcurementRevisionFactory::class)]
#[Fillable([
    'procurement_id',
    'revision_number',
    'source_edition',
    'change_reason',
    'snapshot',
    'snapshot_hash',
    'source_updated_at',
    'detected_at',
    'crawler_run_id',
])]
#[Table(name: 'prc_revisions', keyType: 'int')]
final class ProcurementRevision extends Model
{
    /** @use HasFactory<ProcurementRevisionFactory> */
    use HasFactory;

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'source_updated_at' => 'datetime',
            'detected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Procurement, $this> */
    public function procurement(): BelongsTo
    {
        return $this->belongsTo(related: Procurement::class);
    }
}
