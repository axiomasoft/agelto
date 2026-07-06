<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Enums\Organization\ProcurementPartyRole;
use Database\Factories\Procurement\ProcurementPartyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Участие организации в закупке с ролью (pivot, OCDS parties-подход).
 *
 * `procurement_id` пока без FK — таблица `prc_procurements` появится в другом item.
 *
 * @use HasFactory<ProcurementPartyFactory>
 */
final class ProcurementParty extends Model
{
    /** @use HasFactory<ProcurementPartyFactory> */
    use HasFactory;

    protected $table = 'prc_procurement_parties';

    protected $fillable = [
        'procurement_id',
        'organization_id',
        'role',
        'first_seen_revision_id',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'role' => ProcurementPartyRole::class,
            'attributes' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(related: Organization::class);
    }
}
