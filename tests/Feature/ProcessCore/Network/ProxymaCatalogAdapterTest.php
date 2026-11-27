<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\Control\AcquisitionConfiguration;
use App\Dto\Procurement\Network\AdapterValidation\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterValidation\AdapterValidationClaim;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Control\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\Proxyma\TargetLink;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Proxyma\Caller\AdapterSendGuard;
use App\Services\Procurement\Proxyma\Catalog\BindingAdapter;
use App\Services\Procurement\Proxyma\Catalog\ProcessOwnerAuthority;
use App\Services\Procurement\Proxyma\Catalog\TargetCatalogAdapter;
use App\Services\Procurement\Proxyma\Transport\TlsTrustAdapter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures as P;

beforeEach(fn () => P::clean());
afterEach(fn () => P::clean());

function proxymaMappedTarget(string $source): Target
{
    $row = DB::table('prc_source_targets')->where('id', $source)->sole();
    $id = new Uuid($source);
    DB::table((new TargetLink)->getTable())->insert(['source_target_id' => $source, 'tenant_key' => TenantKey::SYSTEM,
        'target_id' => $id->value, 'source_revision' => $row->policy_version]);
    $target = new Target($id, TenantKey::system(), 'fixture', CanonicalOrigin::fromUrl($row->canonical_origin), 'fixture');
    test()->mock(CatalogQueries::class)->shouldReceive('findTarget')->withArgs(fn ($tenant, $requested) => $tenant->isSystem() && $requested->equals($id))->andReturn($target);

    return $target;
}

test('площадки и providers разрешаются только с точной ownership capability и revision', function (): void {
    $f = new F;
    $target = proxymaMappedTarget($f->target->value);
    $adapter = app(TargetCatalogAdapter::class);
    expect($adapter->platform($f->code, 'card', 'detail')->id->value)->toBe($target->id->value);
    foreach ([fn () => $adapter->platform($f->code, 'card', 'download'),
        fn () => $adapter->platform('foreign', 'card', 'detail'),
        fn () => $adapter->provider(new ProviderKey('fixture'), 'card')] as $invalid) {
        expect($invalid)->toThrow(DomainException::class);
    }
    DB::table('prc_source_targets')->where('id', $f->target->value)->increment('policy_version');
    expect(fn () => $adapter->platform($f->code, 'card', 'detail'))->toThrow(DomainException::class);
});

test('provider catalog не становится platform и не меняет origin debt при чтении mapping', function (): void {
    $f = new F;
    app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(new ProviderKey('fixture'), [
        new SourceTargetDefinition('provider:fixture:generate', 'provider', $f->url->origin, [$f->profile]),
    ]));
    HistoricalNetworkFixtures::synchronize();
    $id = DB::table('prc_source_targets')->where('provider_key', 'fixture')->sole()->id;
    $target = proxymaMappedTarget($id);
    $debt = DB::table('prc_network_budgets')->orderBy('scope_key')->get()->all();
    expect(app(TargetCatalogAdapter::class)->provider(new ProviderKey('fixture'), 'provider:fixture:generate')->id->value)->toBe($target->id->value)
        ->and(DB::table('prc_network_budgets')->orderBy('scope_key')->get()->all())->toEqual($debt);
});

test('redirect допускает только объявленный origin того же owner и capability', function (): void {
    $f = new F;
    $target = proxymaMappedTarget($f->target->value);
    $adapter = app(TargetCatalogAdapter::class);
    expect($adapter->redirect($target->id, $target->id, $target->origin)->id)->toEqual($target->id);
    expect(fn () => $adapter->redirect($target->id, $target->id, CanonicalOrigin::fromUrl('https://foreign.test')))
        ->toThrow(DomainException::class);
    DB::table('prc_source_targets')->where('id', $f->target->value)->update(['retired_at' => DB::raw('clock_timestamp()')]);
    expect(fn () => $adapter->redirect($target->id, $target->id, $target->origin))->toThrow(DomainException::class);
});

test('старые snapshots сохраняют stage profile ID и version через явные immutable mappings', function (): void {
    $crawler = AcquisitionProfileFixtures::create();
    $source = $crawler->route_profile_id;
    $mapped = LedgerFixtures::uuid()->value;
    DB::table('prc_proxyma_profile_links')->insert([
        ['source_profile_id' => $source, 'source_version' => 1, 'tenant_key' => TenantKey::SYSTEM, 'profile_id' => $mapped, 'profile_version' => 4],
        ['source_profile_id' => $source, 'source_version' => 2, 'tenant_key' => TenantKey::SYSTEM, 'profile_id' => $mapped, 'profile_version' => 5],
    ]);
    $configuration = new AcquisitionConfiguration(1, new ProcessUuid($source), 1, AcquisitionKind::Fresh,
        new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-02'), null, null,
        ['detail' => ['route_profile_id' => new ProcessUuid($source), 'route_policy_version' => 2]]);
    $binding = app(BindingAdapter::class);
    $restored = AcquisitionConfiguration::decode($configuration->encode());
    foreach ([1, 2] as $version) {
        $payload = json_decode($configuration->encode()->json, true, flags: JSON_THROW_ON_ERROR);
        unset($payload['stage_route_profiles']);
        if ($version === 1) {
            unset($payload['requested_from'], $payload['requested_through']);
        }
        $old = AcquisitionConfiguration::decode(new VersionedPayload(json_encode($payload, JSON_THROW_ON_ERROR), $version));
        expect($binding->snapshotProfile($old, StageCode::Detail))->toBe(['profile_id' => $mapped, 'profile_version' => 4]);
    }
    expect($binding->snapshotProfile($restored, StageCode::Listing))->toBe(['profile_id' => $mapped, 'profile_version' => 4])
        ->and($binding->snapshotProfile($restored, StageCode::Detail))->toBe(['profile_id' => $mapped, 'profile_version' => 5]);
    DB::table('prc_proxyma_profile_links')->where('source_version', 2)->delete();
    expect(fn () => $binding->snapshotProfile($restored, StageCode::Detail))->toThrow(DomainException::class);
    expect($binding->resolve(new SubjectRef(TenantKey::system(), 'crawler', (string) $crawler->id))->ancestors())->toHaveCount(2)
        ->and($binding->resolve(new SubjectRef(new TenantKey('foreign'), 'crawler', (string) $crawler->id)))->toBeNull()
        ->and($binding->resolve(new SubjectRef(TenantKey::system(), 'crawler', '999999999')))->toBeNull();
});

test('crawler procurement mapping сохраняет конфликт нескольких активных профилей', function (): void {
    $crawler = AcquisitionProfileFixtures::create();
    $otherProfile = LedgerFixtures::uuid()->value;
    DB::table('prc_network_route_profiles')->insert(['id' => $otherProfile, 'profile_key' => 'fixture.other', 'policy_version' => 1]);
    Crawler::factory()->create([
        'registry_id' => $crawler->registry_id, 'route_profile_id' => $otherProfile, 'is_active' => true, 'filter' => $crawler->filter,
    ]);
    $procurement = Procurement::factory()->create(['registry_id' => $crawler->registry_id]);
    expect(fn () => app(BindingAdapter::class)->forProcurement($procurement->id))
        ->toThrow(OutboundUnavailable::class);
});

test('guard держит только adapter tier и сохраняет hold epoch claim ownership и canary semantics', function (string $change): void {
    $f = new F;
    $contract = new AdapterContractRef($f->code, 'detail', '1', '1');
    app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($f->code, [
        new SourceTargetDefinition('card', 'detail', $f->url->origin, [$f->profile], adapterContract: $contract),
    ]));
    HistoricalNetworkFixtures::synchronize();
    $health = DB::table('prc_adapter_health')->sole();
    $canary = in_array($change, ['canary', 'probe_token'], true);
    $token = $canary ? LedgerFixtures::uuid() : null;
    $handle = $f->context(kind: $canary ? OutboundOperationKind::Probe : OutboundOperationKind::LegacyBridge, probeToken: $token)->lease();
    DB::table('prc_outbound_operations')->where('id', $handle->id->value)->update([
        'platform_id' => $health->platform_id, 'adapter_health_id' => $health->id, 'adapter_health_epoch' => 1]);
    DB::table('prc_adapter_health')->where('id', $health->id)->update(['state' => $canary ? 'validating' : 'healthy',
        'probe_token' => $token?->value, 'probe_until' => $canary ? $handle->deadlineAt : null]);
    $claim = new AdapterValidationClaim(new ProcessUuid($health->id), 1, $contract,
        $change === 'owner' ? LedgerFixtures::uuid() : $handle->id, $canary);
    $target = proxymaMappedTarget($f->target->value);
    $original = $f->plan();
    $plan = new OutboundRequestPlan($original->physicalRequestId, $original->targetId, $original->method, $original->url,
        $original->profile, $original->trafficClass, $original->idempotency, adapterClaim: $change === 'missing' ? null : $claim);
    $guard = app(AdapterSendGuard::class);
    $guard->prepare($plan);
    $authority = app(ProcessOwnerAuthority::class);
    P::activate();
    $ref = $authority->reference($handle);
    $snapshot = $authority->snapshot($ref);
    match ($change) {
        'held' => DB::table('prc_adapter_health')->update(['state' => 'held']),
        'epoch' => DB::table('prc_adapter_health')->increment('health_epoch'),
        'probe_token' => DB::table('prc_adapter_health')->update(['probe_token' => LedgerFixtures::uuid()->value]),
        default => null,
    };
    $request = new WireRequest(new Uuid(LedgerFixtures::uuid()->value), new Uuid($plan->physicalRequestId->value), $target->id, 1,
        $change === 'wire' ? 'HEAD' : 'GET', new SafeUrl($plan->url->reveal()), new RequestTimeoutPolicy(100, 500, 1000, 100), $handle->deadlineAt,
        purpose: $canary ? DemandPurpose::Probe : DemandPurpose::Ordinary);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'for update') || str_contains(strtolower($query->sql), 'for share')) {
            $queries[] = $query->sql;
        }
    });
    $result = P::locked(function ($tx) use ($authority, $ref, $snapshot, $guard, $request, &$queries) {
        $state = $authority->lockAndValidate($ref, $snapshot, $tx);
        $queries = [];

        return $guard->evaluateLocked($request, $state, $tx);
    });
    expect($guard->tier())->toBe(AdmissionLockTier::AdapterGuard);
    if (in_array($change, ['none', 'canary'], true)) {
        expect($result)->toBeNull()->and($queries)->toHaveCount(1)->and($queries[0])->toContain('FOR UPDATE OF h');
    } else {
        expect($result)->toBeInstanceOf(ClaimDenied::class);
    }
    expect(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->count())->toBe(0);
})->with(['none', 'missing', 'held', 'epoch', 'owner', 'canary', 'probe_token', 'wire']);

test('слоты исключают финансовые настройки, а регистрация миграций Proxyma сохраняет действующий legacy selector', function (): void {
    $registry = app(DefinitionRegistry::class);
    $slots = [];
    foreach (['acquisition', 'document', 'reference', 'provider', 'notification'] as $id) {
        foreach ($registry->panel('agelto.'.$id)->slots as $slot) {
            $slots[] = $slot->id;
            expect(implode(',', $slot->settings))->not->toContain('financial');
        }
    }
    expect($slots)->toBe(['detail', 'listing', 'status_refresh', 'download', 'reference', 'llm', 'notification']);
    $migrator = app('migrator');
    expect($migrator->getMigrationFiles($migrator->paths()))->toHaveKeys([
        '2026_11_09_000001_create_pxm_authority_tables',
        '2026_11_10_000014_restore_binding_audit_type',
    ]);
    expect(DB::table('prc_network_authorities')->sole()->authority)->toBe('legacy');
});

test('TLS adapter следует единственной SourceTlsTrust authority и запрещает работу внутри transaction', function (): void {
    $bundle = base_path('tests/Fixtures/ProcessCore/ProxymaReplay/Http/certificates/ca.pem');
    config(['outbound.http.tls_bundles' => ['example.test' => $bundle]]);
    $tls = app(TlsTrustAdapter::class);
    $originTrust = $tls->tlsConfigurationFor(CanonicalOrigin::fromUrl('https://example.test'), new Uuid(LedgerFixtures::uuid()->value));
    expect($originTrust->caBundlePath)->toBe($bundle)
        ->and($originTrust->caBundleSha256?->hex)->toBe(hash_file('sha256', $bundle))
        ->and($tls->proxyConfigurationFor('EXAMPLE.TEST.'))->toEqual($originTrust)
        ->and($tls->proxyConfigurationFor('other.test')->caBundlePath)->toBeNull();
    expect(fn () => P::locked(fn () => $tls->proxyConfigurationFor('example.test')))->toThrow(LogicException::class);
});

test('новый worker scope получает прежние definitions без старых owner contexts и SQL при регистрации', function (): void {
    $before = app(DefinitionRegistry::class);
    $owner = app(ProcessOwnerAuthority::class);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    app()->forgetScopedInstances();
    $after = app(DefinitionRegistry::class);
    expect($after)->not->toBe($before)
        ->and($after->freeze())->toBe($before->freeze())
        ->and(app(ProcessOwnerAuthority::class))->not->toBe($owner)
        ->and($after->selectExtensions(ExtensionKind::LockedAdmissionGuard, ['agelto.adapter'])[0]->implementation)
        ->toBe(app(AdapterSendGuard::class))
        ->and($queries)->toBe([]);
});

test('mapping schema отвергает duplicate target и некорректные selector transitions', function (): void {
    $f = new F;
    proxymaMappedTarget($f->target->value);
    expect(fn () => DB::transaction(fn () => DB::table((new TargetLink)->getTable())->insert([
        'source_target_id' => $f->target->value, 'tenant_key' => TenantKey::SYSTEM, 'target_id' => $f->target->value, 'source_revision' => 1,
    ])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('prc_network_authorities')->update(['authority' => 'proxyma'])))->toThrow(QueryException::class);
});

test('profile binding owner и journal mappings не принимают повтор identity', function (string $kind): void {
    $crawler = AcquisitionProfileFixtures::create();
    $id = LedgerFixtures::uuid()->value;
    [$table, $row] = match ($kind) {
        'profile' => ['prc_proxyma_profile_links', ['source_profile_id' => $crawler->route_profile_id,
            'source_version' => 1, 'tenant_key' => TenantKey::SYSTEM, 'profile_id' => $id, 'profile_version' => 1]],
        'binding' => ['prc_proxyma_binding_links', ['crawler_id' => $crawler->id,
            'slot' => 'listing', 'tenant_key' => TenantKey::SYSTEM, 'binding_id' => $id]],
        'owner' => ['prc_proxyma_owner_links', ['tenant_key' => TenantKey::SYSTEM,
            'owner_type' => 'process_attempt', 'owner_id' => $id, 'generation' => 1, 'operation_id' => $id]],
        'journal' => ['prc_network_cutover_journals', ['id' => $id, 'source_revision' => 'fixture-old',
            'target_revision' => 'fixture-new', 'phase' => 'prepared', 'generation' => 1]],
    };
    DB::table($table)->insert($row);
    expect(fn () => DB::transaction(fn () => DB::table($table)->insert($row)))->toThrow(QueryException::class);
})->with(['profile', 'binding', 'owner', 'journal']);
