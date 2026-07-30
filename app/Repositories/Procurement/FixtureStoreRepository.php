<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
     * @param  array<string, mixed>  $attributes
     */
    public function update(Fixture $fixture, array $attributes): void
    {
        $fixture->update($attributes);
    }

    /**
     * @return Collection<int, Fixture>
     */
    public function all(): Collection
    {
        return Fixture::query()->get();
    }

    /**
     * Все эталоны в порядке кода, при непустом `$onlyCodes` — только перечисленные
     * (`estimate:gold:build`/`estimate:gold:import`, `root/service-layer-map.md` §5).
     *
     * @param  list<string>  $onlyCodes
     * @return Collection<int, Fixture>
     */
    public function inCodeOrder(array $onlyCodes = []): Collection
    {
        $fixtures = $this->all()->sortBy(fn (Fixture $fixture): string => $fixture->code)->values();

        if ($onlyCodes !== []) {
            $fixtures = $fixtures->filter(fn (Fixture $fixture): bool => in_array($fixture->code, $onlyCodes, strict: true))->values();
        }

        return $fixtures;
    }

    /**
     * Пул `gold_source=deterministic` для правила разбиения `tune`/`holdout` (D119,
     * `estimate:eval:run --write-splits`). Пять профильных полей живут в jsonb `type_profile` без
     * индексов (§6.4 document-type-axis.md) — предикат/сортировка/выборка идут через jsonb-путь.
     *
     * @return Collection<int, Fixture>
     */
    public function deterministicGoldPool(): Collection
    {
        return Fixture::query()
            ->where('type_profile->gold_source', GoldSource::Deterministic->value)
            ->orderByRaw("type_profile->>'form'")
            ->orderBy('code')
            ->get(['code', 'source_format', DB::raw("type_profile->>'form' as form")]);
    }
}
