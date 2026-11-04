<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\HealthSnapshot;
use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Enums\Procurement\Network\CircuitState;
use App\Enums\Procurement\Network\HealthScope;
use App\Models\User;
use App\Repositories\Procurement\Network\HealthRepository;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Network\ArtifactResponseStream;
use App\Services\Procurement\Network\NetworkReconcileCategory;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\SourceTlsTrust;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(function (): void {
    F::clean();
    $this->f = new F;
});
afterEach(fn () => F::clean());

pest()->group('network-demand-recovery', 'outbound-transport-and-routing');

test('reap и NetworkReconcileCategory освобождают reserved permit при истёкшем lease операции, но не трогают sent permit до его expires_at', function (): void {
    $f = $this->f;
    $ctxA = $f->context();
    $reserved = $f->reserve($ctxA);
    expect($reserved)->toBeInstanceOf(ScheduledNetworkPermit::class);

    // Истекает lease операции, хотя сам permit ещё не достиг expires_at.
    DB::table('prc_outbound_operations')
        ->where('id', $ctxA->lease()->id->value)
        ->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);

    expect(app(NetworkReconcileCategory::class)->reconcile())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('prc_network_permits')->where('id', $reserved->id->value)->value('state'))->toBe('expired');
});

test('RouteRepository::storeProxy при смене host/port переносит участников профиля на новый route_id и инкрементирует policy_version', function (): void {
    $user = User::factory()->create();
    $routes = app(RouteRepository::class);
    $proxy = app(ProcessTransaction::class)->run(fn () => $routes->storeProxy(
        proxy: null,
        configuration: new ProxyNodeConfiguration(
            service: 'audit-proxy',
            host: '10.10.0.1',
            port: 8080,
            username: 'user1',
            secretRef: 'PROXY_SECRET_1',
            country: 'RU',
            scopes: ['general'],
            enabled: true,
        ),
        actorId: $user->id,
        reason: 'initial proxy',
        event: LedgerFixtures::uuid(),
        message: LedgerFixtures::uuid(),
    ));
    $firstRouteId = (string) $proxy->getAttribute('route_id');

    app(ProcessTransaction::class)->run(fn () => $routes->updateProfile(
        key: 'audit-profile',
        expectedVersion: 0,
        routeIds: [$firstRouteId],
        actorId: $user->id,
        reason: 'init profile',
        event: LedgerFixtures::uuid(),
        message: LedgerFixtures::uuid(),
    ));
    $profileId = (string) DB::table('prc_network_route_profiles')->where('profile_key', 'audit-profile')->value('id');

    // Обновляем на месте без смены host/port: credential_version увеличивается для ротации секрета по ссылке.
    app(ProcessTransaction::class)->run(fn () => $routes->storeProxy(
        proxy: $proxy,
        configuration: new ProxyNodeConfiguration(
            service: 'audit-proxy-renamed',
            host: '10.10.0.1',
            port: 8080,
            username: 'user1',
            secretRef: 'PROXY_SECRET_1',
            country: 'RU',
            scopes: ['general'],
            enabled: true,
        ),
        actorId: $user->id,
        reason: 'rotate secret in place',
        event: LedgerFixtures::uuid(),
        message: LedgerFixtures::uuid(),
    ));
    expect((int) DB::table('prc_network_routes')->where('id', $firstRouteId)->value('credential_version'))->toBe(2);

    // Теперь меняем host/port: создаётся новый route_id, профиль получает policy_version + 1 и нового участника.
    $updated = app(ProcessTransaction::class)->run(fn () => $routes->storeProxy(
        proxy: $proxy->fresh(),
        configuration: new ProxyNodeConfiguration(
            service: 'audit-proxy-renamed',
            host: '10.10.0.2',
            port: 8081,
            username: 'user1',
            secretRef: 'PROXY_SECRET_1',
            country: 'RU',
            scopes: ['general'],
            enabled: true,
        ),
        actorId: $user->id,
        reason: 'host changed',
        event: LedgerFixtures::uuid(),
        message: LedgerFixtures::uuid(),
    ));
    $secondRouteId = (string) $updated->getAttribute('route_id');

    $profileAfter = DB::table('prc_network_route_profiles')->where('id', $profileId)->sole();
    $memberRouteIds = DB::table('prc_network_route_profile_members')
        ->where('profile_id', $profileId)
        ->pluck('route_id')
        ->all();

    expect($secondRouteId)->not->toBe($firstRouteId)
        ->and((int) $profileAfter->policy_version)->toBe(2)
        ->and($memberRouteIds)->toBe([$secondRouteId]);
});

test('HealthRepository::credentialHeld удерживает прокси при сдвиге health_epoch на несколько шагов и SourceTlsTrust нормализует регистр и точку хоста', function (): void {
    $routes = app(RouteRepository::class);
    $routeId = app(ProcessTransaction::class)->run(fn () => $routes->createProxyRoute('proxy:cred-hold-audit', 'SEC_1'));
    $healthId = LedgerFixtures::uuid()->value;

    $snapshot = new HealthSnapshot(
        scope: HealthScope::Route,
        id: $healthId,
        state: CircuitState::Open,
        epoch: 5,
        consecutive: 1,
        openings: 1,
        reason: 'credential_hold',
        openUntil: null,
        stableSince: null,
        probeToken: null,
        probeUntil: null,
    );

    expect(app(HealthRepository::class)->credentialHeld($snapshot, $routeId))->toBeTrue();

    config()->set('outbound.http.tls_bundles', ['zakupki.gov.ru' => '/etc/ssl/certs/eis.pem']);
    expect(app(SourceTlsTrust::class)->optionsFor('  ZAKUPKI.GOV.RU. '))
        ->toBe(['verify' => '/etc/ssl/certs/eis.pem']);
});

test('ArtifactResponseStream идемпотентно удаляет артефакт в деструкторе без close, а OutboundExecutor очищает BinaryBodyArtifact при исключении в record', function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Storage::fake('local');

    $key1 = 'outbound-responses/audit-stream-destruct.bin';
    Storage::disk('local')->put($key1, 'payload-bytes');
    $artifact1 = new ArtifactRef('local', $key1, LedgerFixtures::hash('payload-bytes'));

    $stream = new ArtifactResponseStream($artifact1);
    expect($stream->read(7))->toBe('payload')
        ->and(Storage::disk('local')->exists($key1))->toBeTrue();

    unset($stream);
    expect(Storage::disk('local')->exists($key1))->toBeFalse();

    $wire = new WireFixture;
    try {
        $gate = TransportFixtures::gate();
        $gate->afterRecord = static function (): void {
            throw new RuntimeException('Gate record failed after binary response staged');
        };
        $profile = TransportFixtures::profile(['response_kind' => 'binary', 'max_text_bytes' => null, 'charset_default' => null]);
        $budget = new TransferBudget(5_000_000, 5_000_000);

        expect(fn () => app(OutboundExecutor::class)->execute(
            TransportFixtures::plan($wire->url('/document'), $profile),
            TransportFixtures::context(),
            $gate,
            $wire->policy(),
            $budget,
        ))->toThrow(RuntimeException::class, 'Gate record failed after binary response staged')
            ->and(Storage::disk('local')->allFiles('outbound-responses'))->toBe([]);
    } finally {
        $wire->stop();
    }
});
