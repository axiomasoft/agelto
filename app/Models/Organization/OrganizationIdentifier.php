<?php

declare(strict_types=1);

namespace App\Models\Organization;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $organization_id
 * @property string $platform_code
 * @property string $type
 * @property string $value
 */
#[Fillable(['organization_id', 'platform_code', 'type', 'value'])]
#[Table(name: 'org_organization_identifiers')]
final class OrganizationIdentifier extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(related: Organization::class);
    }
}
