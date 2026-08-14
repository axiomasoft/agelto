<?php

declare(strict_types=1);

namespace App\Models\Procurement\Platform;

use App\Enums\Procurement\StatusMappingApplicability;
use Database\Factories\Procurement\Platform\StatusMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Маппинг сырого статуса источника на канонический статус ядра.
 *
 * @property int|null $platform_id
 * @property int|null $registry_id
 * @property string $canonical_status
 */
#[UseFactory(StatusMappingFactory::class)]
#[Fillable([
    'platform_id',
    'registry_id',
    'source_stage',
    'canonical_status',
])]
#[Table(name: 'prc_status_mappings')]
final class StatusMapping extends Model
{
    /** @use HasFactory<StatusMappingFactory> */
    use HasFactory;

    /** @property-read StatusMappingApplicability $applicability */
    /** @return Attribute<StatusMappingApplicability, never> */
    protected function applicability(): Attribute
    {
        return Attribute::get(fn (): StatusMappingApplicability => StatusMappingApplicability::fromScopeIds(
            platformId: $this->platform_id,
            registryId: $this->registry_id,
        ));
    }

    /** @return BelongsTo<Platform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }

    /** @return BelongsTo<Registry, $this> */
    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }
}
