<?php

declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Dto\Organization\OrganizationIdentifierData;
use App\Models\Organization\Organization;
use App\Models\Organization\OrganizationIdentifier;
use Illuminate\Support\Facades\DB;

/**
 * Единственная точка персистентности `org_organizations`. Бизнес-логика entity resolution
 * (какой ключ приоритетнее, когда обновлять) — в `App\Services\Organization\OrganizationResolver`,
 * этот репозиторий только читает/пишет по уже принятым ключам поиска.
 */
final readonly class OrganizationStoreRepository
{
    public function findByInnAndKpp(string $inn, ?string $kpp): ?Organization
    {
        return Organization::query()
            ->where('inn', $inn)
            ->where('kpp', $kpp)
            ->first();
    }

    public function findByOgrnAndKpp(string $ogrn, ?string $kpp): ?Organization
    {
        return Organization::query()->where('ogrn', $ogrn)->where('kpp', $kpp)->first();
    }

    public function findUniqueByOgrn(string $ogrn): ?Organization
    {
        $matches = Organization::query()->where('ogrn', $ogrn)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function findByIdentifier(OrganizationIdentifierData $identifier): ?Organization
    {
        return OrganizationIdentifier::query()
            ->where('platform_code', $identifier->platformCode)
            ->where('type', $identifier->type)
            ->where('value', $identifier->value)
            ->first()?->organization;
    }

    public function lock(Organization $organization): Organization
    {
        return Organization::query()->lockForUpdate()->findOrFail($organization->id);
    }

    public function lockTaxResolution(?string $inn, ?string $ogrn): void
    {
        foreach (['inn' => $inn, 'ogrn' => $ogrn] as $kind => $value) {
            if ($value !== null) {
                DB::select(query: 'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', bindings: ["org_organizations.{$kind}", $value]);
            }
        }
    }

    /** @param list<OrganizationIdentifierData> $identifiers */
    public function storeIdentifiers(Organization $organization, array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            $organization->identifiers()->firstOrCreate([
                'platform_code' => $identifier->platformCode,
                'type' => $identifier->type,
                'value' => $identifier->value,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Organization
    {
        return Organization::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Organization $organization, array $attributes): void
    {
        $organization->update($attributes);
    }
}
