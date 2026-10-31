<?php

declare(strict_types=1);

namespace Database\Factories\Platforms\Eis;

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

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
            // После D18 дефолт `null` отдаёт НЕСОХРАНЯЕМУЮ модель для реестра fz44 (гейт
            // `Crawler::saving()` требует скоуп) — контракт фабрики в этом репозитории обязывает
            // отдавать валидный дефолт (D108).
            'filter' => new EisSearchFilter(okpd2Codes: ['43']),
            'schedule' => '*/15 * * * *',
            'is_active' => true,
            'route_profile_id' => null,
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
        return $this->state(state: ['route_profile_id' => static function (): string {
            $policy = app(OutboundPolicy::class);

            return app(ProcessTransaction::class)->run(function () use ($policy): string {
                $routes = app(RouteRepository::class);
                $direct = $routes->ensureDirectRoute($policy->directEgressId, $policy->directIndependenceGroup);
                $routes->ensureProfile('direct', $direct);

                return $routes->profile(id: null, key: 'direct')->value;
            });
        }]);
    }

    public function withProxies(Proxy ...$proxies): self
    {
        if ($proxies === []) {
            throw new \InvalidArgumentException('Состоянию withProxies нужен хотя бы один узел.');
        }

        return $this->state(state: ['route_profile_id' => static function () use ($proxies): string {
            return app(ProcessTransaction::class)->run(function () use ($proxies): string {
                $id = (string) Str::uuid();
                DB::table('prc_network_route_profiles')->insert(['id' => $id, 'profile_key' => 'fixture:'.$id, 'policy_version' => 1]);
                foreach ($proxies as $proxy) {
                    DB::table('prc_network_route_profile_members')->insert(['profile_id' => $id, 'route_id' => $proxy->route_id]);
                }

                return $id;
            });
        }]);
    }
}
