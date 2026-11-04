<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Proxy;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Proxy>
 */
final class ProxyFactory extends Factory
{
    protected $model = Proxy::class;

    public function definition(): array
    {
        return [
            'service' => fake()->randomElement(['webshare', 'brightdata']),
            'host' => fake()->domainName(),
            'port' => fake()->numberBetween(int1: 1024, int2: 65535),
            'username' => fake()->userName(),
            'country' => null,
            'scopes' => [],
            'route_id' => static fn (): string => app(ProcessTransaction::class)->run(
                fn (): string => app(RouteRepository::class)->createProxyRoute('fixture:'.Str::uuid(), null),
            ),
        ];
    }

    public function country(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'country' => $code,
        ]);
    }

    /**
     * @param  list<ProxyScope>  $scopes
     */
    public function withScopes(array $scopes): static
    {
        return $this->state(fn (array $attributes): array => [
            'scopes' => $scopes,
        ]);
    }

    /**
     * Узел с открытым глобальным circuit, отдельно от здоровья конкретного target.
     */
    public function open(): static
    {
        return $this->afterCreating(static function (Proxy $proxy): void {
            DB::table('prc_route_health')->insert([
                'id' => (string) Str::uuid(), 'route_id' => $proxy->route_id, 'state' => 'open',
                'health_epoch' => 1, 'state_version' => 1, 'reason_code' => 'proxy_connect', 'open_until' => now()->addMinutes(30),
                'recent_openings' => 1, 'consecutive_failures' => 0,
            ]);
        });
    }
}
