<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Organization\Organization;
use Database\Factories\Procurement\ProcurementPartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Участие организации в закупке с ролью (pivot, OCDS parties-подход).
 *
 * `procurement_id` пока без FK — таблица `prc_procurements` появится в другом item.
 */
#[UseFactory(ProcurementPartyFactory::class)]
#[Fillable([
    'procurement_id',
    'organization_id',
    'role',
    'first_seen_revision_id',
    'attributes',
])]
#[Table(name: 'prc_procurement_parties')]
final class ProcurementParty extends Model
{
    /** @use HasFactory<ProcurementPartyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => ProcurementPartyRole::class,
            'attributes' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(related: Organization::class);
    }
}
