<?php

declare(strict_types=1);

namespace App\Models\Organization;

use App\Models\Procurement\ProcurementParty;
use Carbon\CarbonImmutable;
use Database\Factories\Organization\OrganizationFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Единый реестр организаций (OCDS parties): одна master-запись поверх всех
 * упоминаний, роли в конкретных закупках — в `prc_procurement_parties`.
 */
/**
 * @property string $uuid
 * @property string|null $inn
 * @property string|null $kpp
 * @property string|null $ogrn
 * @property CarbonImmutable|null $attributes_observed_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(OrganizationFactory::class)]
#[Fillable([
    'uuid',
    'inn',
    'kpp',
    'ogrn',
    'full_name',
    'short_name',
    'address_raw',
    'region_code',
    'phone',
    'email',
    'attributes',
    'attributes_observed_at',
])]
#[Table(name: 'org_organizations')]
final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
        ];
    }

    /** @return Attribute<CarbonImmutable|null, DateTimeInterface|null> */
    protected function attributesObservedAt(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?CarbonImmutable => is_string($value) ? CarbonImmutable::parse($value) : null,
            set: static fn (?DateTimeInterface $value): ?string => $value?->format('Y-m-d H:i:s.uP'),
        );
    }

    protected static function booted(): void
    {
        self::creating(function (self $organization): void {
            $organization->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<ProcurementParty, $this> */
    public function procurementParties(): HasMany
    {
        return $this->hasMany(related: ProcurementParty::class);
    }

    /** @return HasMany<OrganizationIdentifier, $this> */
    public function identifiers(): HasMany
    {
        return $this->hasMany(related: OrganizationIdentifier::class);
    }
}
