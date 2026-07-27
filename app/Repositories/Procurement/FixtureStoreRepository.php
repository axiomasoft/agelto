<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Ai\Fixture;
use Illuminate\Database\Eloquent\Collection;

/**
 * Единственная точка персистентности `prc_ai_fixtures` (D6) — реестр эталонных ФАЙЛОВ.
 */
final readonly class FixtureStoreRepository
{
    public function findByCode(string $code): ?Fixture
    {
        return Fixture::query()->where('code', $code)->first();
    }

    public function findBySha256(string $sha256): ?Fixture
    {
        return Fixture::query()->where('sha256', $sha256)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function storeOrUpdate(string $code, array $attributes): Fixture
    {
        return Fixture::query()->updateOrCreate(['code' => $code], $attributes);
    }

    /**
     * @return Collection<int, Fixture>
     */
    public function all(): Collection
    {
        return Fixture::query()->get();
    }
}
