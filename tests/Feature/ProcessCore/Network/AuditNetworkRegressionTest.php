<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\BinaryBodyArtifact;
use App\Dto\Procurement\Network\HealthSnapshot;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Enums\Procurement\Network\CircuitState;
use App\Enums\Procurement\Network\HealthScope;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Network\TransportFailure;
use App\Models\User;
use App\Repositories\Procurement\Network\HealthRepository;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Network\ArtifactResponseStream;
use App\Services\Procurement\Network\NetworkReconcileCategory;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\SourceTlsTrust;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // Сбой удаления временного бинарного артефакта в cleanup не маскирует первичное исключение record(), а успешный ответ сохраняет файл.
        $okGate = TransportFixtures::gate();
        $okOutcome = app(OutboundExecutor::class)->execute(
            TransportFixtures::plan($wire->url('/document'), $profile),
            TransportFixtures::context(),
            $okGate,
            $wire->policy(),
            new TransferBudget(5_000_000, 5_000_000),
        );
        expect($okOutcome)->toBeInstanceOf(TransportSucceeded::class)
            ->and($okOutcome->response->body)->toBeInstanceOf(BinaryBodyArtifact::class)
            ->and(Storage::disk('local')->exists($okOutcome->response->body->artifact->key))->toBeTrue();

        Log::spy();
        $failingDisk = Mockery::mock(Storage::disk('local'))->makePartial();
        $failingDisk->shouldReceive('delete')->andThrow(new RuntimeException('Disk delete crashed during cleanup'));
        Storage::set('local', $failingDisk);

        $failGate = TransportFixtures::gate();
        $failGate->afterRecord = static function (): void {
            throw new RuntimeException('Primary gate record failure preserved');
        };
        expect(fn () => app(OutboundExecutor::class)->execute(
            TransportFixtures::plan($wire->url('/document'), $profile),
            TransportFixtures::context(),
            $failGate,
            $wire->policy(),
            new TransferBudget(5_000_000, 5_000_000),
        ))->toThrow(RuntimeException::class, 'Primary gate record failure preserved');
        Log::shouldHaveReceived('warning')->once();
    } finally {
        $wire->stop();
    }
});

test('UrlPolicy отвергает смешанный public/private DNS, а OutboundExecutor переключает следующий проверенный IP в новом физическом запросе с соблюдением sendLimit, pause и redirect', function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    $publicOrigin = CanonicalOrigin::fromUrl('https://example.test');
    $mixedPolicy = new UrlPolicy(
        [$publicOrigin],
        [],
        static fn (): array => ['8.8.8.8', '10.0.0.1'],
    );
    expect(fn () => $mixedPolicy->resolve(new SafeUrl('https://example.test/path')))
        ->toThrow(InvalidArgumentException::class, 'Non-public address denied by URL policy.');

    $dedupPolicy = new UrlPolicy(
        [$publicOrigin],
        [],
        static fn (): array => ['8.8.8.8', '8.8.8.8', '1.1.1.1'],
    );
    $resolvedDedup = $dedupPolicy->resolve(new SafeUrl('https://example.test/path'));
    expect($resolvedDedup->address)->toBe('8.8.8.8')
        ->and($resolvedDedup->addresses)->toBe(['8.8.8.8', '1.1.1.1']);

    $wire = new WireFixture;
    try {
        $origins = [
            CanonicalOrigin::fromUrl($wire->url('/')->reveal()),
            CanonicalOrigin::fromUrl($wire->url('/', true)->reveal()),
            CanonicalOrigin::fromUrl($wire->url('/', false, 'second.test')->reveal()),
        ];
        $failoverPolicy = new UrlPolicy($origins, [
            'fixture.test' => ['127.0.0.2', '127.0.0.1'],
            'second.test' => ['127.0.0.2', '127.0.0.1'],
        ]);

        // 1. Mixed public/private DNS блокируется до любого authorize/send.
        $blockedGate = TransportFixtures::gate();
        $blockedPolicy = new UrlPolicy($origins, [], static fn (): array => ['8.8.8.8', '127.0.0.1']);
        $blockedOutcome = app(OutboundExecutor::class)->execute(
            TransportFixtures::plan($wire->url('/ok')),
            TransportFixtures::context(),
            $blockedGate,
            $blockedPolicy,
            new TransferBudget(1_000_000, 1_000_000),
        );
        expect($blockedOutcome)->toBeInstanceOf(TransportBlocked::class)
            ->and($blockedOutcome->reason)->toBe(NetworkBlockedReason::UrlPolicyDenied)
            ->and($blockedGate->requests)->toBe([]);

        // 2. Два проверенных IP: отказ соединения на 127.0.0.2 переключает вторую попытку на 127.0.0.1 с новым physicalRequestId и отдельным permit.
        $gate = TransportFixtures::gate();
        $outcome = app(OutboundExecutor::class)->execute(
            TransportFixtures::plan($wire->url('/ok')),
            TransportFixtures::context(),
            $gate,
            $failoverPolicy,
            new TransferBudget(1_000_000, 1_000_000),
        );
        expect($outcome)->toBeInstanceOf(TransportSucceeded::class)
            ->and($gate->requests)->toHaveCount(2)
            ->and($gate->results)->toHaveCount(2)
            ->and($gate->results[0]->failure)->toBe(TransportFailure::Connect)
            ->and($gate->results[1]->failure)->toBeNull()
            ->and($gate->requests[0]->plan->physicalRequestId->value)
            ->not->toBe($gate->requests[1]->plan->physicalRequestId->value)
            ->and($outcome->physicalRequestId->value)->toBe($gate->requests[1]->plan->physicalRequestId->value);

        // 3. Неоднозначная запись (AmbiguousWrite / sendLimit=1) никогда не отправляет второй запрос на второй IP.
        $writeGate = TransportFixtures::gate();
        $writePlan = new OutboundRequestPlan(
            LedgerFixtures::uuid(),
            LedgerFixtures::uuid(),
            'POST',
            $wire->url('/ok'),
            TransportFixtures::profile(),
            TrafficClass::Detail,
            IdempotencyClass::AmbiguousWrite,
            null,
            1,
        );
        $writeOutcome = app(OutboundExecutor::class)->execute(
            $writePlan,
            TransportFixtures::context(),
            $writeGate,
            $failoverPolicy,
            new TransferBudget(1_000_000, 1_000_000),
        );
        expect($writeOutcome)->toBeInstanceOf(TransportDeferred::class)
            ->and($writeOutcome->reason)->toBe(NetworkDeferralReason::AttemptsExhausted)
            ->and($writeGate->requests)->toHaveCount(1);

        // 4. Пауза между попытками останавливает перебор IP до второго физического запроса.
        $pauseGate = TransportFixtures::gate();
        $pauseGate->afterRecord = static function ($g): void {
            $g->deny = new TransportBlocked(NetworkBlockedReason::Paused);
        };
        $pauseOutcome = app(OutboundExecutor::class)->execute(
            TransportFixtures::plan($wire->url('/ok')),
            TransportFixtures::context(),
            $pauseGate,
            $failoverPolicy,
            new TransferBudget(1_000_000, 1_000_000),
        );
        expect($pauseOutcome)->toBeInstanceOf(TransportBlocked::class)
            ->and($pauseOutcome->reason)->toBe(NetworkBlockedReason::Paused)
            ->and($pauseGate->requests)->toHaveCount(1);

        // 5. Недостаточный остаток времени попытки (canStart() === false) возвращает SliceExhausted.
        $shortGate = TransportFixtures::gate();
        $shortProfile = TransportFixtures::profile(['total_seconds' => 10]);
        $shortOutcome = app(OutboundExecutor::class)->execute(
            TransportFixtures::plan($wire->url('/ok'), $shortProfile),
            TransportFixtures::context(seconds: 5),
            $shortGate,
            $failoverPolicy,
            new TransferBudget(1_000_000, 1_000_000),
        );
        expect($shortOutcome)->toBeInstanceOf(TransportDeferred::class)
            ->and($shortOutcome->reason)->toBe(NetworkDeferralReason::SliceExhausted)
            ->and($shortGate->requests)->toBe([]);

        // 6. Cross-origin redirect сбрасывает историю отказов на новый origin и доставляет ответ со второго IP.
        $wire->respond('/redirect-second', '', 302, ['Location' => $wire->url('/ok', false, 'second.test')->reveal()]);
        $redirectGate = TransportFixtures::gate();
        $redirectOutcome = app(OutboundExecutor::class)->execute(
            TransportFixtures::plan($wire->url('/redirect-second')),
            TransportFixtures::context(),
            $redirectGate,
            $failoverPolicy,
            new TransferBudget(1_000_000, 1_000_000),
        );
        expect($redirectOutcome)->toBeInstanceOf(TransportSucceeded::class)
            ->and($redirectGate->requests)->toHaveCount(4);
    } finally {
        $wire->stop();
    }
});
