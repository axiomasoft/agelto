<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Services\Procurement\Network\NetworkProbeReconcileCategory;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Proxyma\AdapterSendGuard;
use App\Services\Procurement\Proxyma\TlsTrustAdapter;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;

beforeEach(fn () => ProxymaFixtures::clean());
afterEach(fn () => ProxymaFixtures::clean());

test('демон восстанавливает просроченный второй маршрут', function (bool $credentialHold, string $scope, bool $expiredProbe): void {
    $wire = new WireFixture;
    try {
        $fixture = new PermitFixtures($wire);
        $first = HistoricalNetworkFixtures::proxy(['host' => '127.0.0.1', 'port' => $wire->ports['proxy']])->route_id;
        $second = $fixture->route->routeId->value;
        $profileId = HistoricalNetworkFixtures::profile(key: 'probe-regression', routes: [$first, $second]);
        config(['outbound.routing.default_profile' => 'probe-regression']);
        $profile = TransportFixtures::profile([
            'code' => 'network.probe', 'attempts' => 1, 'distinct_routes' => 1, 'delays_ms' => [],
        ]);
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($fixture->code, [
            new SourceTargetDefinition('card', 'detail', $fixture->url->origin, [$fixture->profile, $profile], probeUrl: $fixture->url->reveal()),
        ]));
        HistoricalNetworkFixtures::synchronize();
        ProxymaCallerFixtures::install($wire);
        $tables = PackageSchema::tables();
        expect(DB::table($tables['route_profile_members'])->where('profile_id', $profileId)->orderBy('position')->pluck('route_id')->all())
            ->toBe([$first, $second]);

        $firstHealth = DB::table($tables['route_health'])->where('route_id', $first);
        $past = DB::raw("clock_timestamp() - interval '1 second'");
        if ($credentialHold) {
            $firstHealth->update(['state' => 'open', 'reason_code' => 'credential_hold', 'open_until' => $past]);
        }
        $secondHealth = DB::table($tables[$scope])->where('route_id', $second);
        $secondHealth->update($expiredProbe
            ? ['state' => 'half_open', 'probe_token' => Uuid::random()->value, 'probe_until' => $past]
            : ['state' => 'open', 'open_until' => $past]);
        $firstBefore = $firstHealth->sole();
        $wire->respond('/ok', 'healthy');

        $processed = app(NetworkProbeReconcileCategory::class)->reconcile();
        expect($firstHealth->sole())->toEqual($firstBefore);
        if ($expiredProbe) {
            expect($processed)->toBe(0)
                ->and($wire->receipts())->toBeEmpty()
                ->and($secondHealth->sole()->reason_code)->toBe('probe_expired')
                ->and($secondHealth->sole()->probe_token)->toBeNull();

            return;
        }

        expect($processed)->toBe(1)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DB::table($tables['permits'])->sole()->route_id)->toBe($second)
            ->and(DB::table($tables['operations'])->sole()->purpose)->toBe('probe');
    } finally {
        $wire->stop();
    }
})->with([
    'первый маршрут здоров, срок открытия второго истёк' => [false, 'route_health', false],
    'первый маршрут удерживается, срок открытия пары второго истёк' => [true, 'route_target_health', false],
    'первый маршрут здоров, аренда пробы второго истекла' => [false, 'route_health', true],
]);

test('долгоживущий guard освобождает планы и сохраняет идентичность живого запроса', function (): void {
    $fixture = new PermitFixtures;
    $guard = app(AdapterSendGuard::class);
    $plan = $fixture->plan();
    $physicalId = $plan->physicalRequestId;
    $guard->prepare($plan);
    $guard->prepare($plan);
    expect(fn () => $guard->prepare($fixture->plan(physicalId: $physicalId)))->toThrow(LogicException::class);

    $reference = WeakReference::create($plan);
    unset($plan);
    expect($reference->get())->toBeNull();

    for ($index = 0; $index < 128; $index++) {
        $plan = $fixture->plan();
        $guard->prepare($plan);
        $reference = WeakReference::create($plan);
        unset($plan);
        expect($reference->get())->toBeNull();
    }
    $replacement = $fixture->plan(physicalId: $physicalId);
    $guard->prepare($replacement);
});

test('TLS отклоняет недоступный, слишком большой или невалидный CA-бандл', function (?string $contents): void {
    $path = tempnam(sys_get_temp_dir(), 'proxyma-ca-');
    if ($contents === null) {
        unlink($path);
    } else {
        file_put_contents($path, $contents);
    }
    try {
        config(['outbound.http.tls_bundles' => ['example.test' => $path]]);
        $tls = app(TlsTrustAdapter::class);
        $origin = CanonicalOrigin::fromUrl('https://example.test');
        $target = Uuid::random();
        expect(fn () => $tls->tlsConfigurationFor(origin: $origin, targetId: $target))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $tls->proxyConfigurationFor('example.test'))->toThrow(InvalidArgumentException::class);
    } finally {
        if ($contents !== null) {
            unlink($path);
        }
    }
})->with([
    'отсутствующий файл' => [null],
    'невалидный сертификат в блоке PEM' => ["-----BEGIN CERTIFICATE-----\naW52YWxpZA==\n-----END CERTIFICATE-----\n"],
    'размер больше четырёх мебибайт' => [str_repeat(' ', 4 * 1024 * 1024 + 1)],
]);
