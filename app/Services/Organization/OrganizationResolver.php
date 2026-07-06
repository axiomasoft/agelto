<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Dto\Procurement\Details\OrganizationData;
use App\Models\Organization\Organization;
use App\Repositories\Organization\OrganizationStoreRepository;

/**
 * Единственная точка создания/поиска `org_organizations` (entity resolution по ИНН/ОГРН,
 * `01-database.md` §2): одна организация во многих ролях/закупках — одна master-запись.
 * Найдена → обновить изменившиеся атрибуты (faithful-to-source); не найдена → создать.
 * Сама персистентность — в {@see OrganizationStoreRepository}, этот класс только решает,
 * по какому ключу искать и когда обновлять.
 */
final readonly class OrganizationResolver
{
    public function __construct(
        private OrganizationStoreRepository $storeRepository,
    ) {}

    public function resolve(OrganizationData $data): Organization
    {
        $organization = $this->find($data);

        if ($organization === null) {
            return $this->storeRepository->create($this->attributes($data));
        }

        $this->updateChangedAttributes($organization, $data);

        return $organization;
    }

    private function find(OrganizationData $data): ?Organization
    {
        if ($data->inn !== null) {
            $organization = $this->storeRepository->findByInnAndKpp(inn: $data->inn, kpp: $data->kpp);

            if ($organization !== null) {
                return $organization;
            }
        }

        if ($data->ogrn !== null) {
            return $this->storeRepository->findByOgrn(ogrn: $data->ogrn);
        }

        return null;
    }

    private function updateChangedAttributes(Organization $organization, OrganizationData $data): void
    {
        $attributes = $this->attributes($data);

        $changed = array_filter(
            $attributes,
            static fn (mixed $value, string $key): bool => $value !== null && $organization->{$key} !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changed !== []) {
            $this->storeRepository->update($organization, $changed);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(OrganizationData $data): array
    {
        return [
            'inn' => $data->inn,
            'kpp' => $data->kpp,
            'ogrn' => $data->ogrn,
            'full_name' => $data->fullName,
            'address_raw' => $data->addressRaw,
            'phone' => $data->phone,
            'email' => $data->email,
        ];
    }
}
