<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Dto\Procurement\Details\OrganizationData;
use App\Models\Organization\Organization;
use App\Repositories\Organization\OrganizationStoreRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
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
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private OrganizationStoreRepository $storeRepository,
    ) {}

    public function resolve(OrganizationData $data, ?DateTimeInterface $observedAt = null): Organization
    {
        if ($data->inn === null && $data->ogrn === null && $data->identifiers === []) {
            throw new InvalidArgumentException('Для организации требуется ИНН, ОГРН или внешний идентификатор.');
        }

        $observedAt = $observedAt === null ? CarbonImmutable::now() : CarbonImmutable::instance($observedAt);
        for ($attempt = 1; ; $attempt++) {
            $organization = $this->find($data);

            try {
                return $this->persist(data: $data, organization: $organization, observedAt: $observedAt);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    private function persist(OrganizationData $data, ?Organization $organization, CarbonImmutable $observedAt): Organization
    {
        return DB::transaction(function () use ($data, $organization, $observedAt): Organization {
            if (($data->inn !== null || $data->ogrn !== null) && ($organization === null
                || ($data->inn !== null && $organization->inn !== $data->inn)
                || ($data->ogrn !== null && $organization->ogrn !== $data->ogrn)
                || $organization->kpp !== $data->kpp)) {
                $this->storeRepository->lockTaxResolution(inn: $data->inn, ogrn: $data->ogrn);
                $organization = $this->find($data);
            }

            if ($organization === null) {
                $organization = $this->storeRepository->create($this->attributes(data: $data, observedAt: $observedAt));
            } else {
                $organization = $this->storeRepository->lock($organization);
                $this->updateChangedAttributes(organization: $organization, data: $data, observedAt: $observedAt);
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
            $matches[] = $this->storeRepository->findByOgrnAndKpp(ogrn: $data->ogrn, kpp: $data->kpp);
        }

        foreach ($data->identifiers as $identifier) {
            $matches[] = $this->storeRepository->findByIdentifier($identifier);
        }

        $organizations = collect($matches)->filter()->keyBy('id');
        if ($organizations->isEmpty()) {
            return $this->findWithIncompleteTaxIdentity($data);
        }

        if ($organizations->count() > 1) {
            throw new RuntimeException('Идентификаторы организации указывают на разные записи; автоматическое слияние запрещено.');
        }

        return $organizations->first();
    }

    private function findWithIncompleteTaxIdentity(OrganizationData $data): ?Organization
    {
        if ($data->kpp === null) {
            return $data->ogrn === null ? null : $this->storeRepository->findUniqueByOgrn($data->ogrn);
        }

        $byInn = $data->inn === null ? null : $this->storeRepository->findByInnAndKpp(inn: $data->inn, kpp: null);
        $byOgrn = $data->ogrn === null ? null : $this->storeRepository->findByOgrnAndKpp(ogrn: $data->ogrn, kpp: null);
        if ($byInn !== null && $byOgrn !== null && $byInn->id !== $byOgrn->id) {
            throw new RuntimeException('Идентификаторы организации указывают на разные записи; автоматическое слияние запрещено.');
        }

        return $byInn ?? $byOgrn;
    }

    private function updateChangedAttributes(Organization $organization, OrganizationData $data, CarbonImmutable $observedAt): void
    {
        $attributes = $this->attributes(data: $data, observedAt: $observedAt);
        $identity = array_intersect_key($attributes, array_flip(['inn', 'kpp', 'ogrn']));

        foreach ($identity as $key => $value) {
            if ($value !== null && $organization->getAttribute($key) !== null && $organization->getAttribute($key) !== $value) {
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

        $previous = $organization->attributes_observed_at ?? $organization->updated_at;
        $changed = array_filter(
            $previous !== null && $observedAt->lt($previous) ? $identity : $attributes,
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
    private function attributes(OrganizationData $data, CarbonImmutable $observedAt): array
    {
        return [
            'inn' => $data->inn,
            'kpp' => $data->kpp,
            'ogrn' => $data->ogrn,
            'full_name' => $data->fullName,
            'address_raw' => $data->addressRaw,
            'phone' => $data->phone,
            'email' => $data->email,
            'attributes_observed_at' => $observedAt,
        ];
    }
}
