<?php

declare(strict_types=1);

namespace Database\Factories\Platforms\Eis;

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\LaravelData\Data;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;

/**
 * @extends Factory<Crawler>
 */
final class EisCrawlerFactory extends Factory
{
    protected $model = Crawler::class;

    public function definition(): array
    {
        return [
            'registry_id' => static function (): int {
                $platform = Platform::query()->firstOrCreate(
                    attributes: ['code' => 'eis'],
                    values: [
                        'name' => 'Единая информационная система',
                        'base_url' => 'https://zakupki.gov.ru',
                        'is_active' => true,
                        'is_public' => true,
                    ],
                );

                return Registry::query()->firstOrCreate(
                    attributes: ['platform_id' => $platform->getKey(), 'code' => 'fz44'],
                    values: ['name' => '44-ФЗ', 'settings' => []],
                )->getKey();
            },
            'name' => fake()->words(asText: true),
            'listing_kind' => AcquisitionKind::Fresh,
            // `Crawler::saving()` требует непустой скоуп; дефолт фабрики должен сохраняться.
            'filter' => new EisSearchFilter(okpd2Codes: ['43']),
            'schedule' => '*/15 * * * *',
            'is_active' => true,
            'route_profile_id' => null,
        ];
    }

    /**
     * Типизированный фильтр профиля: состояние принимает готовый `Data`-объект, а не
     * массив ключей — набирать фильтр строками в фабрике значит воспроизводить ровно тот отказ
     * в тишине, ради которого контракт заводился.
     */
    public function filter(Data $filter): self
    {
        return $this->state(state: ['filter' => $filter->toArray()]);
    }

    public function withoutProxy(): self
    {
        return $this->state(state: ['route_profile_id' => static function (): string {
            return HistoricalNetworkFixtures::directProfile();
        }]);
    }

    public function withProxies(object ...$proxies): self
    {
        if ($proxies === []) {
            throw new \InvalidArgumentException('Состоянию withProxies нужен хотя бы один узел.');
        }

        return $this->state(state: ['route_profile_id' => static function () use ($proxies): string {
            return app(ProcessTransaction::class)->run(static fn (): string => HistoricalNetworkFixtures::profileForProxies($proxies));
        }]);
    }
}
