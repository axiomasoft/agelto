<?php

declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Models\Organization\Organization;
use Illuminate\Database\UniqueConstraintViolationException;

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

    public function findByOgrn(string $ogrn): ?Organization
    {
        return Organization::query()->where('ogrn', $ogrn)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Organization
    {
        try {
            return Organization::query()->create($attributes);
        } catch (UniqueConstraintViolationException $exception) {
            // Гонка: другой процесс уже создал орг. с тем же (inn, kpp) — резолвим в неё (аналог D9).
            return $this->findByInnAndKpp(inn: $attributes['inn'], kpp: $attributes['kpp']) ?? throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Organization $organization, array $attributes): void
    {
        $organization->update($attributes);
    }
}
