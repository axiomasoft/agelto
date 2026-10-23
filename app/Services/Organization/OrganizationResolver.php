<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Dto\Procurement\Details\OrganizationData;
use App\Models\Organization\Organization;
use App\Repositories\Organization\OrganizationStoreRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

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
        if ($data->inn === null && $data->ogrn === null && $data->identifiers === []) {
            throw new InvalidArgumentException('Для организации требуется ИНН, ОГРН или внешний идентификатор.');
        }

        $organization = $this->find($data);

        try {
            return $this->persist($data, $organization);
        } catch (UniqueConstraintViolationException $exception) {
            // persist() rolls back its savepoint before repeating the full identity lookup.
            $organization = $this->find($data) ?? throw $exception;

            return $this->persist($data, $organization);
        }
    }

    private function persist(OrganizationData $data, ?Organization $organization): Organization
    {
        return DB::transaction(function () use ($data, $organization): Organization {
            if ($organization === null) {
                $organization = $this->storeRepository->create($this->attributes($data));
            } else {
                $organization = $this->storeRepository->lock($organization);
                $this->updateChangedAttributes($organization, $data);
            }

            $this->storeRepository->storeIdentifiers($organization, $data->identifiers);
            $organization->unsetRelation('identifiers');

            return $organization;
        });
    }

    private function find(OrganizationData $data): ?Organization
    {
        $matches = [];

        if ($data->inn !== null) {
            $matches[] = $this->storeRepository->findByInnAndKpp(inn: $data->inn, kpp: $data->kpp);
        }

        if ($data->ogrn !== null) {
            $matches[] = $this->storeRepository->findByOgrn(ogrn: $data->ogrn);
        }

        foreach ($data->identifiers as $identifier) {
            $matches[] = $this->storeRepository->findByIdentifier($identifier);
        }

        $organizations = collect($matches)->filter()->keyBy('id');

        if ($organizations->count() > 1) {
            throw new RuntimeException('Идентификаторы организации указывают на разные записи; автоматическое слияние запрещено.');
        }

        return $organizations->first();
    }

    private function updateChangedAttributes(Organization $organization, OrganizationData $data): void
    {
        $attributes = $this->attributes($data);

        foreach (['inn', 'kpp', 'ogrn'] as $key) {
            if ($attributes[$key] !== null && $organization->getAttribute($key) !== null && $organization->getAttribute($key) !== $attributes[$key]) {
                throw new RuntimeException('Противоречивые идентификаторы организации; обновление запрещено.');
            }
        }

        foreach ($data->identifiers as $identifier) {
            $existing = $organization->identifiers
                ->where('platform_code', $identifier->platformCode)
                ->firstWhere('type', $identifier->type);

            if ($existing !== null && $existing->value !== $identifier->value) {
                throw new RuntimeException('Противоречивые внешние идентификаторы организации; обновление запрещено.');
            }
        }

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
