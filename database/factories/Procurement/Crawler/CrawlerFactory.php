<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\LaravelData\Data;

/**
 * @extends Factory<Crawler>
 */
final class CrawlerFactory extends Factory
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
            'execution_lane' => CrawlerExecutionLane::Standard,
            'mode' => CrawlerMode::Fresh,
            'stop_policy' => RunStopPolicy::Default,
            'source_method' => SourceMethod::Csv,
            'fallback_method' => SourceMethod::Html,
            // После D18 дефолт `null` отдаёт НЕСОХРАНЯЕМУЮ модель для реестра fz44 (гейт
            // `Crawler::saving()` требует скоуп) — контракт фабрики в этом репозитории обязывает
            // отдавать валидный дефолт (D108).
            'filter' => new EisSearchFilter(okpd2Codes: ['43']),
            'schedule' => '*/15 * * * *',
            'options' => [
                'records_per_page' => fake()->numberBetween(int1: 20, int2: 100),
            ],
            'is_active' => true,
            'proxy_mode' => CrawlerProxyMode::Default,
            'proxy_country' => null,
        ];
    }

    /**
     * Типизированный фильтр профиля (P4.3): состояние принимает готовый `Data`-объект, а не
     * массив ключей — набирать фильтр строками в фабрике значит воспроизводить ровно тот отказ
     * в тишине, ради которого контракт заводился.
     */
    public function filter(Data $filter): self
    {
        return $this->state(state: ['filter' => $filter->toArray()]);
    }

    /**
     * Политика остановки «только актуальные данные» (P3.3) — легальна только у fresh-профиля
     * (гейт `Crawler::saving()`), поэтому состояние фиксирует `mode` тем же приёмом.
     */
    public function onlyFresh(): self
    {
        return $this->state(state: ['mode' => CrawlerMode::Fresh, 'stop_policy' => RunStopPolicy::OnlyFresh]);
    }

    public function withoutProxy(): self
    {
        return $this->state(state: ['proxy_mode' => CrawlerProxyMode::Direct]);
    }

    public function proxyCountry(string $country): self
    {
        return $this->state(state: ['proxy_country' => $country]);
    }

    public function withProxies(Proxy ...$proxies): self
    {
        if ($proxies === []) {
            throw new \InvalidArgumentException('Состоянию withProxies нужен хотя бы один узел.');
        }

        return $this
            ->state(state: ['proxy_mode' => CrawlerProxyMode::Direct])
            ->afterCreating(static function (Crawler $crawler) use ($proxies): void {
                $crawler->proxies()->sync(array_map(static fn (Proxy $proxy): int => $proxy->id, $proxies));
                $crawler->update(['proxy_mode' => CrawlerProxyMode::Profile]);
            });
    }
}
