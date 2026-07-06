<?php

declare(strict_types=1);

namespace App\Models\Organization;

use Database\Factories\Organization\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Единый реестр организаций (OCDS parties): одна master-запись поверх всех
 * упоминаний, роли в конкретных закупках — в `prc_procurement_parties`.
 */
#[UseFactory(OrganizationFactory::class)]
final class Organization extends Model
{
    use HasFactory;

    protected $table = 'org_organizations';

    protected $fillable = [
        'uuid',
        'inn',
        'kpp',
        'ogrn',
        'eruz_number',
        'full_name',
        'short_name',
        'address_raw',
        'region_code',
        'phone',
        'email',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $organization): void {
            $organization->uuid ??= (string) Str::uuid7();
        });
    }

    public function procurementParties(): HasMany
    {
        return $this->hasMany(related: ProcurementParty::class);
    }
}
