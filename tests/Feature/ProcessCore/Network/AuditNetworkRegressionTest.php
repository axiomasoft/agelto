<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\BinaryBodyArtifact;
use App\Dto\Procurement\Network\HealthSnapshot;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Dto\Procurement\Network\ReservedProviderNetwork;
use App\Dto\Procurement\Network\ResolvedUrl;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\SelectedNetworkRoute;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportOutcome;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Procurement\Network\CircuitState;
use App\Enums\Procurement\Network\HealthScope;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Network\TransportFailure;
use App\Models\User;
use App\Repositories\Procurement\Network\HealthRepository;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Procurement\Network\ArtifactResponseStream;
use App\Services\Procurement\Network\Contracts\OutboundAttemptGate;
use App\Services\Procurement\Network\HttpTransport;
use App\Services\Procurement\Network\NetworkReconcileCategory;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\SourceTlsTrust;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ToolContext;

beforeEach(function (): void {
    F::clean();
    $this->f = new F;
});
afterEach(fn () => F::clean());

pest()->group('network-demand-recovery', 'outbound-transport-and-routing');

function auditProxyConfig(string $service, string $host, int $port): ProxyNodeConfiguration
{
    return new ProxyNodeConfiguration(
        service: $service,
        host: $host,
        port: $port,
        username: 'user1',
        secretRef: 'PROXY_SECRET_1',
        country: 'RU',
        scopes: ['general'],
        enabled: true,
    );
}

function auditExecuteOutbound(
    OutboundRequestPlan $plan,
    OutboundAttemptGate $gate,
    UrlPolicy $policy,
    int $maxBytes = 1_000_000,
    int $contextSeconds = 30,
): TransportOutcome {
    return app(OutboundExecutor::class)->execute(
        $plan,
        TransportFixtures::context(seconds: $contextSeconds),
        $gate,
        $policy,
        new TransferBudget($maxBytes, $maxBytes),
    );
}

test('reap и NetworkReconcileCategory освобождают reserved permit при истёкшем lease операции, но не трогают sent permit до его expires_at', function (): void {
    $f = $this->f;
    $ctxA = $f->context();
    $reserved = $f->reserve($ctxA);
    expect($reserved)->toBeInstanceOf(ScheduledNetworkPermit::class);

    DB::table('prc_outbound_operations')
        ->where('id', $ctxA->lease()->id->value)
        ->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);

    expect(app(NetworkReconcileCategory::class)->reconcile())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('prc_network_permits')->where('id', $reserved->id->value)->value('state'))->toBe('expired');
});

test('RouteRepository::storeProxy при смене host/port переносит участников профиля на новый route_id и инкрементирует policy_version', function (): void {
    $user = User::factory()->create();
    $routes = app(RouteRepository::class);
    $tx = app(ProcessTransaction::class);

    $proxy = $tx->run(fn () => $routes->storeProxy(
        null, auditProxyConfig('audit-proxy', '10.10.0.1', 8080), $user->id, 'initial proxy', ProcessUuid::random(), ProcessUuid::random(),
    ));
    $firstRouteId = (string) $proxy->getAttribute('route_id');

    $tx->run(fn () => $routes->updateProfile(
        'audit-profile', 0, [$firstRouteId], $user->id, 'init profile', ProcessUuid::random(), ProcessUuid::random(),
    ));
    $profileId = (string) DB::table('prc_network_route_profiles')->where('profile_key', 'audit-profile')->value('id');

    $tx->run(fn () => $routes->storeProxy(
        $proxy, auditProxyConfig('audit-proxy-renamed', '10.10.0.1', 8080), $user->id, 'rotate secret in place', ProcessUuid::random(), ProcessUuid::random(),
    ));
    expect((int) DB::table('prc_network_routes')->where('id', $firstRouteId)->value('credential_version'))->toBe(2);

    $updated = $tx->run(fn () => $routes->storeProxy(
        $proxy->fresh(), auditProxyConfig('audit-proxy-renamed', '10.10.0.2', 8081), $user->id, 'host changed', ProcessUuid::random(), ProcessUuid::random(),
    ));
    $secondRouteId = (string) $updated->getAttribute('route_id');

    $profileAfter = DB::table('prc_network_route_profiles')->where('id', $profileId)->sole();
    $memberRouteIds = DB::table('prc_network_route_profile_members')->where('profile_id', $profileId)->pluck('route_id')->all();

    expect($secondRouteId)->not->toBe($firstRouteId)
        ->and((int) $profileAfter->policy_version)->toBe(2)
        ->and($memberRouteIds)->toBe([$secondRouteId]);

    // SourceCatalogRepository::reason редактирует секреты в аудит-причине retireTarget.
    $targetRow = DB::table('prc_source_targets')->whereNull('retired_at')->first();
    assert($targetRow !== null);
    $retireEvent = ProcessUuid::random();
    $retiredResult = $tx->run(fn () => app(SourceCatalogRepository::class)->retireTarget(
        new ProcessUuid((string) $targetRow->id),
        (int) $targetRow->policy_version,
        $user->id,
        'retire with Bearer secret-catalog-token-999',
        $retireEvent,
        ProcessUuid::random(),
    ));
    $transitionDetails = (string) DB::table('prc_process_transitions')->where('event_id', $retireEvent->value)->value('details');
    expect($retiredResult->status)->toBe(NetworkChangeStatus::Applied)
        ->and($transitionDetails)->toContain('[redacted]')
        ->and($transitionDetails)->not->toContain('secret-catalog-token-999');
});

test('HealthRepository::credentialHeld удерживает прокси при сдвиге health_epoch на несколько шагов и SourceTlsTrust нормализует регистр и точку хоста', function (): void {
    $routeId = app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->createProxyRoute('proxy:cred-hold-audit', 'SEC_1'));
    $snapshot = new HealthSnapshot(
        HealthScope::Route, ProcessUuid::random()->value, CircuitState::Open, 5, 1, 1, 'credential_hold', null, null, null, null,
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
    $stream = new ArtifactResponseStream(new ArtifactRef('local', $key1, LedgerFixtures::hash('payload-bytes')));
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

        expect(fn () => auditExecuteOutbound(TransportFixtures::plan($wire->url('/document'), $profile), $gate, $wire->policy(), 5_000_000))
            ->toThrow(RuntimeException::class, 'Gate record failed after binary response staged')
            ->and(Storage::disk('local')->allFiles('outbound-responses'))->toBe([]);

        $okOutcome = auditExecuteOutbound(TransportFixtures::plan($wire->url('/document'), $profile), TransportFixtures::gate(), $wire->policy(), 5_000_000);
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
        expect(fn () => auditExecuteOutbound(TransportFixtures::plan($wire->url('/document'), $profile), $failGate, $wire->policy(), 5_000_000))
            ->toThrow(RuntimeException::class, 'Primary gate record failure preserved');
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
    $mixedPolicy = new UrlPolicy([$publicOrigin], [], static fn (): array => ['8.8.8.8', '10.0.0.1']);
    expect(fn () => $mixedPolicy->resolve(new SafeUrl('https://example.test/path')))
        ->toThrow(InvalidArgumentException::class, 'Non-public address denied by URL policy.');

    $dedupPolicy = new UrlPolicy([$publicOrigin], [], static fn (): array => ['8.8.8.8', '8.8.8.8', '1.1.1.1']);
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

        $blockedGate = TransportFixtures::gate();
        $blockedPolicy = new UrlPolicy($origins, [], static fn (): array => ['8.8.8.8', '127.0.0.1']);
        $blockedOutcome = auditExecuteOutbound(TransportFixtures::plan($wire->url('/ok')), $blockedGate, $blockedPolicy);
        expect($blockedOutcome)->toBeInstanceOf(TransportBlocked::class)
            ->and($blockedOutcome->reason)->toBe(NetworkBlockedReason::UrlPolicyDenied)
            ->and($blockedGate->requests)->toBe([]);

        $gate = TransportFixtures::gate();
        $outcome = auditExecuteOutbound(TransportFixtures::plan($wire->url('/ok')), $gate, $failoverPolicy);
        expect($outcome)->toBeInstanceOf(TransportSucceeded::class)
            ->and($gate->requests)->toHaveCount(2)
            ->and($gate->results)->toHaveCount(2)
            ->and($gate->results[0]->failure)->toBe(TransportFailure::Connect)
            ->and($gate->results[1]->failure)->toBeNull()
            ->and($gate->requests[0]->plan->physicalRequestId->value)
            ->not->toBe($gate->requests[1]->plan->physicalRequestId->value)
            ->and($outcome->physicalRequestId->value)->toBe($gate->requests[1]->plan->physicalRequestId->value);

        $writeGate = TransportFixtures::gate();
        $writePlan = new OutboundRequestPlan(
            ProcessUuid::random(), ProcessUuid::random(), 'POST', $wire->url('/ok'),
            TransportFixtures::profile(), TrafficClass::Detail, IdempotencyClass::AmbiguousWrite, null, 1,
        );
        $writeOutcome = auditExecuteOutbound($writePlan, $writeGate, $failoverPolicy);
        expect($writeOutcome)->toBeInstanceOf(TransportDeferred::class)
            ->and($writeOutcome->reason)->toBe(NetworkDeferralReason::AttemptsExhausted)
            ->and($writeGate->requests)->toHaveCount(1);

        $pauseGate = TransportFixtures::gate();
        $pauseGate->afterRecord = static function ($g): void {
            $g->deny = new TransportBlocked(NetworkBlockedReason::Paused);
        };
        $pauseOutcome = auditExecuteOutbound(TransportFixtures::plan($wire->url('/ok')), $pauseGate, $failoverPolicy);
        expect($pauseOutcome)->toBeInstanceOf(TransportBlocked::class)
            ->and($pauseOutcome->reason)->toBe(NetworkBlockedReason::Paused)
            ->and($pauseGate->requests)->toHaveCount(1);

        $shortGate = TransportFixtures::gate();
        $shortProfile = TransportFixtures::profile(['total_seconds' => 10]);
        $shortOutcome = auditExecuteOutbound(TransportFixtures::plan($wire->url('/ok'), $shortProfile), $shortGate, $failoverPolicy, 1_000_000, 5);
        expect($shortOutcome)->toBeInstanceOf(TransportDeferred::class)
            ->and($shortOutcome->reason)->toBe(NetworkDeferralReason::SliceExhausted)
            ->and($shortGate->requests)->toBe([]);

        $wire->respond('/redirect-second', '', 302, ['Location' => $wire->url('/ok', false, 'second.test')->reveal()]);
        $redirectGate = TransportFixtures::gate();
        $redirectOutcome = auditExecuteOutbound(TransportFixtures::plan($wire->url('/redirect-second')), $redirectGate, $failoverPolicy);
        expect($redirectOutcome)->toBeInstanceOf(TransportSucceeded::class)
            ->and($redirectGate->requests)->toHaveCount(4);
    } finally {
        $wire->stop();
    }
});

test('UrlPolicy отклоняет зарезервированные диапазоны IPv6 NAT64 Teredo 6to4 и IPv4-translated, а HttpTransport::prepareProvider проверяет согласованность плана и отсутствие транзакции', function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    $origin = CanonicalOrigin::fromUrl('https://example.test');
    foreach (['::ffff:0:127.0.0.1', '64:ff9b:1::1', '2001::1', '2001:db8::1', '2002:7f00:1::'] as $reservedIpv6) {
        $policy = new UrlPolicy([$origin], [], static fn (): array => [$reservedIpv6]);
        expect(fn () => $policy->resolve(new SafeUrl('https://example.test/v1')))
            ->toThrow(InvalidArgumentException::class, 'Non-public address denied by URL policy.');
    }

    $transport = app(HttpTransport::class);
    $url = new SafeUrl('https://example.test/v1beta/models/gemini:generateContent');
    $otherUrl = new SafeUrl('https://example.test/v1beta/models/other:generateContent');
    $physicalId = ProcessUuid::random();
    $profile = TransportFixtures::profile();
    $wirePost = new ProviderWireRequest(
        operation: FinancialFixtures::genericPlan('prepare-provider-audit'),
        method: 'POST',
        endpoint: $url->reveal(),
        allowedOrigin: 'https://example.test',
        headers: [],
        contentType: 'application/json',
        body: '{"contents":[]}',
    );

    $makeNetwork = static function (SafeUrl $planUrl, SafeUrl $resolvedUrl, string $method, ?ContentHash $bodyHash) use ($physicalId, $profile): ReservedProviderNetwork {
        $plan = new OutboundRequestPlan(
            $physicalId, ProcessUuid::random(), $method, $planUrl, $profile, TrafficClass::Provider,
            $method === 'GET' ? IdempotencyClass::ReadOnly : IdempotencyClass::AmbiguousWrite, $bodyHash, 1,
        );
        $routeId = ProcessUuid::random();
        $selection = new NetworkRouteSelection($routeId);
        $route = new SelectedNetworkRoute($selection, new HttpRouteBinding($routeId));
        $ticket = new NetworkDemandTicket(ProcessUuid::random(), 1, $plan, $selection);
        $permit = new ScheduledNetworkPermit(
            $ticket->id, $ticket, new DateTimeImmutable('now', new DateTimeZone('UTC')), new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC')),
        );

        return new ReservedProviderNetwork($permit, $route, new ResolvedUrl($resolvedUrl, '127.0.0.1', ['127.0.0.1']));
    };

    $validPostNetwork = $makeNetwork($url, $url, 'POST', new ContentHash($wirePost->bodyHash));
    $ctx = ToolContext::make();

    expect($transport->prepareProvider($wirePost, $physicalId->value, $validPostNetwork, $ctx, 'key', hrtime(true) + 10_000_000_000))
        ->not->toBeNull();

    $wireGet = new ProviderWireRequest(
        operation: FinancialFixtures::genericPlan('prepare-provider-get', OperationPurpose::Lookup),
        method: 'GET',
        endpoint: $url->reveal(),
        allowedOrigin: 'https://example.test',
        headers: [],
        contentType: 'application/json',
        body: '',
    );
    expect($transport->prepareProvider($wireGet, $physicalId->value, $makeNetwork($url, $url, 'GET', null), $ctx, 'key', hrtime(true) + 10_000_000_000))
        ->not->toBeNull();

    foreach ([
        [ProcessUuid::random()->value, $validPostNetwork],
        [$physicalId->value, $makeNetwork($url, $otherUrl, 'POST', new ContentHash($wirePost->bodyHash))],
        [$physicalId->value, $makeNetwork($otherUrl, $otherUrl, 'POST', new ContentHash($wirePost->bodyHash))],
        [$physicalId->value, $makeNetwork($url, $url, 'GET', null)],
        [$physicalId->value, $makeNetwork($url, $url, 'POST', LedgerFixtures::hash('wrong-body'))],
    ] as [$callId, $network]) {
        expect(fn () => $transport->prepareProvider($wirePost, $callId, $network, $ctx, 'key', hrtime(true) + 10_000_000_000))
            ->toThrow(LogicException::class);
    }

    DB::beginTransaction();
    try {
        expect(fn () => $transport->prepareProvider($wirePost, $physicalId->value, $validPostNetwork, $ctx, 'key', hrtime(true) + 10_000_000_000))
            ->toThrow(LogicException::class, 'Outbound I/O is forbidden inside a database transaction.');
    } finally {
        DB::rollBack();
    }
});
