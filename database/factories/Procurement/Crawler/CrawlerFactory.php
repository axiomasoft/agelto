<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Models\Procurement\Crawler\Crawler;
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
            'registry_id' => Registry::factory(),
            'name' => fake()->words(asText: true),
            'mode' => CrawlerMode::Fresh,
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
            'use_proxy' => true,
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

    public function withoutProxy(): self
    {
        return $this->state(state: ['use_proxy' => false]);
    }

    public function proxyCountry(string $country): self
    {
        return $this->state(state: ['proxy_country' => $country]);
    }
}
