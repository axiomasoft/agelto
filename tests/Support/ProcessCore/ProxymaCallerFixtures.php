<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Network\Control\HttpRouteBinding;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\TrafficClass;
use App\Models\Procurement\Proxyma\ProfileLink;
use App\Models\Procurement\Proxyma\TargetLink;
use App\Services\Procurement\Network\LegacyOutboundClient;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Proxyma\Caller\AdapterSendGuard;
use App\Services\Procurement\Proxyma\Caller\CallerGate;
use App\Services\Procurement\Proxyma\Caller\CallerPolicyContext;
use App\Services\Procurement\Proxyma\Caller\RuntimeCaller;
use App\Services\Procurement\Proxyma\Catalog\ProcessOwnerAuthority;
use App\Services\Procurement\Proxyma\Catalog\ProfileBindings;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\BindingCommands;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\DestinationResolver;
use Proxyma\Contracts\ExtensionProvider;
use Proxyma\Contracts\PolicyCommands;
use Proxyma\Contracts\SecretResolver;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Contracts\WorkBudget;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\ReservationInput;
use Proxyma\Domain\Catalog as C;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResponseProfile;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Policy as P;
use Proxyma\Infrastructure\Database\CatalogProvisioner;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Security\StandaloneCatalogMutationFence;
use Tests\Support\ProcessCore\Http\WireFixture;

final class ProxymaCallerFixtures implements ExtensionProvider
{
    public function register(DefinitionRegistry $registry): void
    {
        $registry->registerPanel(new P\PanelDefinition('default', 'agelto.subjects',
            array_map(static fn (TrafficClass $traffic): PanelSlot => new PanelSlot($traffic->value,
                ['timeout.connect_ms', 'timeout.read_ms', 'timeout.total_ms', 'retry.max_attempts', 'retry.delays_ms',
                    'http.max_redirects', 'route.allow_direct', 'routes.denied', 'destinations.allowed', 'route.strategy']), TrafficClass::cases())));
    }

    public static function install(WireFixture $wire, string $routeStrategy = 'uniform_random', bool $copyLegacyBudgetConstraints = false): void
    {
        if (getenv('PROCESS_CORE_TEST') !== '1' || DB::selectOne('SELECT current_database() AS db')->db !== 'agelto_test') {
            throw new \LogicException('Caller fixtures require isolated agelto_test.');
        }
        ProxymaFixtures::resetAuthority();
        $package = base_path('vendor/axiomasoft/proxyma');
        // RefreshDatabase удаляет tables/migration records, но PostgreSQL trigger functions остаются.
        // Guard выше и stopped serial rig ограничивают cleanup только disposable package schema.
        if (! DB::table('migrations')->where('migration', '2026_11_09_000001_create_pxm_authority_tables')->exists()) {
            foreach (DB::select("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname='public' AND p.proname LIKE 'pxm\\_%' ESCAPE '\\' AND p.prorettype='trigger'::regtype AND p.pronargs=0") as $function) {
                $name = DB::connection()->getQueryGrammar()->wrap($function->proname);
                DB::statement("DROP FUNCTION {$name}() CASCADE");
            }
        }
        if (Artisan::call('migrate', ['--path' => [$package.'/database/migrations', $package.'/src/Laravel/Migrations'],
            '--realpath' => true, '--force' => true]) !== 0) {
            throw new \RuntimeException('Package caller migrations failed.');
        }
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'pxm\\_%' ESCAPE '\\'");
        DB::statement('TRUNCATE '.implode(', ', array_map(fn ($r) => '"'.$r->tablename.'"', $tables)).' CASCADE');
        DB::statement('TRUNCATE prc_proxyma_owner_links, prc_proxyma_binding_links, prc_proxyma_profile_links, prc_proxyma_target_links');
        self::configure();
        $tenant = P\TenantKey::system();
        $actor = fn () => new C\CatalogMutationActor(new C\ActorRef('worker', 'fixture-caller'), 'isolated local caller', 0, Uuid::random()->value);
        app(PolicyCommands::class)->update(new P\UpdatePolicyCommand(Uuid::random(), $tenant, P\PolicyScope::Global,
            P\TenantKey::SYSTEM, '{"schema_version":1,"operations":{}}', $actor()));
        $catalog = app(CatalogCommands::class);
        foreach (DB::table('prc_network_routes')->get() as $route) {
            $endpoint = null;
            $kind = C\RouteKind::from($route->kind);
            if ($kind === C\RouteKind::Proxy) {
                $proxy = DB::table('prc_proxies')->where('route_id', $route->id)->sole();
                $endpoint = Uuid::random();
                $catalog->upsertEndpoint(new C\UpsertEndpointCommand($endpoint, $tenant, C\ProxyProtocol::Http,
                    $proxy->host, (int) $proxy->port, null, $actor()));
            }
            $catalog->upsertRoute(new C\UpsertRouteCommand(new Uuid($route->id), $tenant, $route->route_key,
                $kind, $endpoint, true, C\EgressIdentity::explicit($route->egress_id), null, false, $actor()));
        }
        $routeRows = DB::table('prc_network_routes')->get(['id', 'egress_id']);
        $routeIds = $routeRows->pluck('id')->map(fn ($id) => new Uuid($id))->all();
        $fallback = Uuid::random();
        $catalog->upsertProfile(new C\UpsertRouteProfileCommand($fallback, $tenant, 'fixture-caller', $routeIds, 'uniform_random', $actor()));
        foreach (DB::table('prc_network_route_profiles')->get() as $profile) {
            $ids = DB::table('prc_network_route_profile_members')->where('profile_id', $profile->id)->pluck('route_id')->map(fn ($id) => new Uuid($id))->all();
            $id = new Uuid($profile->id);
            $catalog->upsertProfile(new C\UpsertRouteProfileCommand($id, $tenant, $profile->profile_key, $ids, $routeStrategy, $actor()));
            DB::table((new ProfileLink)->getTable())->insert(['source_profile_id' => $profile->id, 'source_version' => $profile->policy_version,
                'tenant_key' => P\TenantKey::SYSTEM, 'profile_id' => $id->value, 'profile_version' => 1]);
            if ($profile->profile_key === app(OutboundPolicy::class)->defaultRouteProfile) {
                $fallback = $id;
            }
        }
        $origins = [];
        $ports = [];
        /** @var array<string, P\BudgetConstraint> $acceptanceBudgets */
        $acceptanceBudgets = [];
        foreach (DB::table('prc_source_targets')->whereNull('retired_at')->get() as $target) {
            $id = new Uuid($target->id);
            $origin = C\CanonicalOrigin::fromUrl($target->canonical_origin);
            $budgets = [$origin->budgetScopeKey() => self::budgetConstraint($origin->budgetScopeKey(), 2, $copyLegacyBudgetConstraints)];
            foreach ($routeRows as $route) {
                $routeScope = $origin->routeScopeKey(new Uuid($route->id));
                $routeBudget = self::budgetConstraint($routeScope, 2, $copyLegacyBudgetConstraints);
                $budgets[$routeScope] = $routeBudget;
                $egressScope = $origin->egressScopeKey(new C\EgressIdentity($route->egress_id));
                $egressBudget = new P\BudgetConstraint($egressScope, $routeBudget->emissionIntervalUs, $routeBudget->burst,
                    $routeBudget->maxInflight, true, $routeBudget->hourLimit, $routeBudget->dayLimit);
                $budgets[$egressScope] = isset($budgets[$egressScope])
                    ? $budgets[$egressScope]->intersect($egressBudget)
                    : $egressBudget;
            }
            $catalog->upsertTarget(new C\UpsertTargetCommand($id, $tenant, 'source:'.$target->id, $origin, 'default', $actor(), array_values($budgets)));
            $probeBudget = self::budgetConstraint($origin->probeScopeKey(), $copyLegacyBudgetConstraints ? 2 : 1, $copyLegacyBudgetConstraints);
            DB::transaction(function () use ($tenant, $probeBudget): void {
                $provisioner = new CatalogProvisioner(DB::connection());
                $provisioner->lockCatalog($tenant);
                $provisioner->materialize($tenant, [$probeBudget]);
            });
            if ($copyLegacyBudgetConstraints) {
                foreach ([...$budgets, $probeBudget] as $budget) {
                    $acceptanceBudgets[$budget->scopeKey] = isset($acceptanceBudgets[$budget->scopeKey])
                        ? $acceptanceBudgets[$budget->scopeKey]->intersect($budget)
                        : $budget;
                }
            }
            DB::table((new TargetLink)->getTable())->insert(['source_target_id' => $target->id, 'tenant_key' => P\TenantKey::SYSTEM,
                'target_id' => $target->id, 'source_revision' => $target->policy_version]);
            $origins[$target->id] = [$origin->host => ['127.0.0.1']];
            $ports[] = $origin->port;
        }
        foreach (DB::table('prc_source_targets')->whereNotNull('platform_id')->distinct()->pluck('platform_id') as $id) {
            foreach (TrafficClass::cases() as $traffic) {
                app(BindingCommands::class)->bindPolicy(new P\BindSubjectPolicyCommand(new P\SubjectRef($tenant, 'platform', (string) $id),
                    $fallback, 'fixture-caller', $actor(), slotKey: $traffic->value));
            }
        }
        foreach (DB::table('prc_source_targets')->where('capability', 'provider')->whereNotNull('provider_key')->distinct()->pluck('provider_key') as $provider) {
            app(BindingCommands::class)->bindPolicy(new P\BindSubjectPolicyCommand(new P\SubjectRef($tenant, 'provider', $provider),
                $fallback, 'fixture-caller', $actor(), 'agelto.provider', 'llm'));
        }
        foreach (TrafficClass::cases() as $traffic) {
            app(BindingCommands::class)->bindPolicy(new P\BindSubjectPolicyCommand(new P\SubjectRef($tenant, 'system', 'network'),
                $fallback, 'fixture-caller', $actor(), slotKey: $traffic->value));
        }
        foreach (['platform' => DB::table('prc_source_targets')->whereNotNull('platform_id')->distinct()->pluck('platform_id')->all(),
            'system' => ['network']] as $type => $ids) {
            foreach ($ids as $id) {
                foreach (['acquisition' => ['listing', 'detail', 'status_refresh'], 'document' => ['download'],
                    'reference' => ['reference'], 'notification' => ['notification']] as $panel => $slots) {
                    foreach ($slots as $slot) {
                        app(BindingCommands::class)->bindPolicy(new P\BindSubjectPolicyCommand(new P\SubjectRef($tenant, $type, (string) $id),
                            $fallback, 'fixture-caller', $actor(), 'agelto.'.$panel, $slot));
                    }
                }
            }
        }
        foreach (['route_health', 'route_target_health', 'profile_health', 'target_health'] as $name) {
            DB::table(PackageSchema::tables()[$name])->update($name === 'target_health' ? ['state' => 'closed', 'availability' => 'available'] : ['state' => 'closed']);
        }
        if ($copyLegacyBudgetConstraints) {
            self::assertBudgetConstraints($tenant, $acceptanceBudgets);
        }
        app()->instance(LegacyOutboundClient::class, app()->makeWith(LegacyOutboundClient::class, ['urls' => $wire->policy()]));
        ProxymaFixtures::activate();
    }

    public static function installAcceptanceProfile(WireFixture $wire): void
    {
        self::install($wire, copyLegacyBudgetConstraints: true);
    }

    private static function budgetConstraint(string $scopeKey, int $defaultMaxInflight, bool $copyLegacyBudgetConstraints): P\BudgetConstraint
    {
        if (! $copyLegacyBudgetConstraints) {
            return new P\BudgetConstraint($scopeKey, 100_000, 1, $defaultMaxInflight, true);
        }

        $budget = DB::table('prc_network_budgets')->where('scope_key', $scopeKey)
            ->sole(['emission_interval_us', 'burst', 'max_inflight']);

        return new P\BudgetConstraint($scopeKey, (int) $budget->emission_interval_us, (int) $budget->burst,
            (int) $budget->max_inflight, true);
    }

    /** @param array<string, P\BudgetConstraint> $expected */
    private static function assertBudgetConstraints(P\TenantKey $tenant, array $expected): void
    {
        foreach ($expected as $scopeKey => $constraint) {
            $budget = DB::table(PackageSchema::tables()['budgets'])->where('tenant_key', $tenant->value)->where('scope_key', $scopeKey)
                ->sole(['emission_interval_us', 'burst', 'max_inflight', 'mandatory']);
            if ((int) $budget->emission_interval_us !== $constraint->emissionIntervalUs || (int) $budget->burst !== $constraint->burst
                || (int) $budget->max_inflight !== $constraint->maxInflight
                || ! in_array($budget->mandatory, [true, 1, '1', 't'], true)) {
                throw new \LogicException("Native budget constraint differs from declared legacy scope {$scopeKey}");
            }
        }
    }

    public static function configure(): void
    {
        if (getenv('PROCESS_CORE_TEST') !== '1' || DB::selectOne('SELECT current_database() AS db')->db !== 'agelto_test') {
            throw new \LogicException('Caller runtime requires isolated agelto_test.');
        }
        app()->forgetScopedInstances();
        app()->instance(CatalogMutationFence::class, new StandaloneCatalogMutationFence);
        DB::connection()->setTransactionManager(app('db.transactions'));
        // Historical parity cases разрешают retries/redirects явно; endpoint caller bounds остаются верхним пределом.
        config(['proxyma.wire.digest_key' => 'fixture-only-caller-hmac-key-32-bytes', 'proxyma.policy.project_defaults' => [
            'route.allow_direct' => true, 'retry.max_attempts' => 5, 'http.max_redirects' => 3],
            'proxyma.extensions.approved_providers.caller-fixture' => self::class,
            'proxyma.extensions.providers' => ['agelto', 'caller-fixture']]);
        app()->instance(TenantContextResolver::class, new class implements TenantContextResolver
        {
            public function resolve(): P\TenantContext
            {
                return new P\TenantContext(P\TenantKey::system(), new C\ActorRef('worker', 'fixture-caller'));
            }
        });
        app()->instance(Authorizer::class, new class implements Authorizer
        {
            public function authorize(C\ActorRef $actor, P\TenantKey $tenant, string $ability, Uuid $resourceId): void
            {
                if (! $tenant->isSystem() || $actor->id !== 'fixture-caller') {
                    throw new \RuntimeException('Foreign caller fixture actor.');
                }
            }
        });
        $origins = [];
        $ports = [];
        foreach (DB::table('prc_source_targets')->whereNull('retired_at')->get() as $target) {
            $origin = C\CanonicalOrigin::fromUrl($target->canonical_origin);
            $origins[$target->id] = [$origin->host => ['127.0.0.1']];
            $ports[] = $origin->port;
        }
        $dns = new DnsResolver(app(ConnectionScope::class), static fn (): array => ['127.0.0.1']);
        app()->instance(DestinationResolver::class, new DestinationPolicy($dns, $origins, array_values(array_unique($ports))));
    }

    public static function gate(string $route): CallerGate
    {
        $policy = new ProfileBindings([new HttpRouteBinding(new ProcessUuid($route))]);

        return app()->makeWith(CallerGate::class, ['routes' => app(ProfileResolver::class)->outboundRoutes($policy)]);
    }

    /** @return array{0: ReservationInput, 1: ScheduledPermit, 2: SealedWire, 3: OperationCorrelation, 4: C\Target} */
    public static function reserve(OutboundRequestPlan $plan, OutboundExecutionContext $context): array
    {
        return app(RuntimeCaller::class)->run($plan, $context, function () use ($plan, $context): array {
            $tenant = P\TenantKey::system();
            $targetId = DB::table((new TargetLink)->getTable())->where('tenant_key', $tenant->value)
                ->where('source_target_id', $plan->targetId->value)->value('target_id');
            if (! is_string($targetId)) {
                throw new \LogicException('Caller target has no package mapping.');
            }
            $target = app(CatalogQueries::class)->findTarget($tenant, new Uuid($targetId));
            if ($target === null || $target->isRetired()) {
                throw new \LogicException('Caller target is not active in the package catalog.');
            }
            $settings = CallerPolicyContext::resolve($plan, $context);
            $effective = app(PolicyResolver::class)->resolveEffective($settings->subjects, $target->id, $settings->slot, panelKey: $settings->panel, bypassCache: true);
            $total = min($plan->profile->totalSeconds * 1000, $effective->timeouts->totalTimeoutMs);
            $attempts = min($plan->maxAttempts(), $effective->timeouts->maxAttempts);
            $delays = array_slice($plan->profile->delaysMs, 0, $attempts - 1);
            if ($attempts <= 1) {
                $delays = [];
            } elseif (($effective->explanations['retry.delays_ms']->explicit ?? false) && $effective->timeouts->retryDelaysMs !== []) {
                $delays = array_map(
                    fn (int $delay, int $index): int => min($plan->profile->delaysMs[$index] ?? PHP_INT_MAX, $delay),
                    array_slice($effective->timeouts->retryDelaysMs, 0, $attempts - 1),
                    array_keys(array_slice($effective->timeouts->retryDelaysMs, 0, $attempts - 1)),
                );
            }
            $timeouts = new RequestTimeoutPolicy(
                min($total, $plan->profile->connectSeconds * 1000, $effective->timeouts->connectTimeoutMs),
                min($total, $plan->profile->lowSpeedSeconds * 1000, $effective->timeouts->readTimeoutMs),
                $total,
                max(15_000, $effective->timeouts->cleanupMarginMs),
                $attempts,
                min($attempts, $plan->profile->distinctRoutes, $effective->timeouts->maxDistinctRoutes),
                min($plan->profile->redirects, $effective->timeouts->maxRedirects),
                retryDelaysMs: $delays,
            );
            $lease = $context->lease();
            $operation = new OperationCorrelation(new Uuid($plan->physicalRequestId->value), app(ProcessOwnerAuthority::class)->reference($lease),
                min($lease->leaseUntil, $lease->deadlineAt), $plan->physicalRequestId->value);
            $packageContext = new ExecutionContext($operation, $settings->subjects, new Duration($context->remainingSlice()->milliseconds),
                hrtime(true) + $context->remainingSlice()->nanos());
            $request = new WireRequest($operation->operationId, new Uuid($plan->physicalRequestId->value), $target->id,
                $effective->effectiveRevision, $plan->method, new SafeUrl($plan->url->reveal()), $timeouts, $operation->deadlineAt, new PreparedHeaders,
                idempotency: IdempotencyClass::from($plan->idempotency->value), trafficClass: $settings->slot,
                responseProfile: new ResponseProfile($plan->profile->maxTextBytes === null ? 'binary' : 'raw', $plan->profile->maxEntityBytes,
                    $plan->profile->maxEntityBytes, $plan->profile->maxTextBytes ?? $plan->profile->maxEntityBytes));
            $destination = app(DestinationResolver::class)->resolve($request->url, $packageContext, $timeouts->connectTimeoutMs, $target);
            $selected = app(RouteSelector::class)->select($tenant, $effective->routeProfileId ?? throw new AdmissionRejected('profile_missing'),
                $effective, $request, new RouteSelectionContext);
            $route = $selected->route ?? throw new AdmissionRejected('route_unavailable');
            $selection = $selected->selection ?? throw new AdmissionRejected('route_unavailable');
            $credential = null;
            $proxy = null;
            $proxyTls = null;
            if ($route->kind === C\RouteKind::Proxy) {
                $endpoint = $route->endpointId === null ? null : app(CatalogQueries::class)->findEndpoint($tenant, $route->endpointId);
                if ($endpoint === null || $endpoint->isRetired()) {
                    throw new AdmissionRejected('proxy_endpoint_missing');
                }
                $proxy = app(ProxyResolver::class)->resolve($endpoint, $timeouts->connectTimeoutMs);
                if ($endpoint->credential !== null) {
                    $credential = app(SecretResolver::class)->resolve($endpoint->credential, $route->tenantKey)
                        ?? throw new AdmissionRejected('proxy_credential_missing');
                }
                if ($endpoint->protocol === C\ProxyProtocol::Https) {
                    $proxyTls = app(TlsTrustPolicy::class)->proxyConfigurationFor($endpoint->host);
                }
            }
            $wire = app(WireSealer::class)->seal($request, $route, $destination,
                app(TlsTrustPolicy::class)->tlsConfigurationFor($request->url->origin, $target->id), $credential, $proxyTls, $proxy);
            app(AdapterSendGuard::class)->prepare($plan);
            app(OperationCommands::class)->create($operation, $selection->profileId, $target->id, $timeouts);
            $work = new class($context) implements WorkBudget
            {
                public function __construct(private OutboundExecutionContext $context) {}

                public function canStart(Duration $required): bool
                {
                    $this->context->tick();

                    return $this->context->canStart(new \App\Dto\Procurement\Process\Primitives\Duration($required->milliseconds));
                }
            };
            $reservations = app(ReservationService::class);
            $input = $reservations->prepare($wire->request, $operation, $selection, $effective, $work);
            $ticket = $reservations->enqueue($input, $work);
            if ($ticket instanceof DemandWait) {
                throw new AdmissionRejected('fixture_reservation_deferred');
            }
            $permit = $reservations->reserve($ticket, $input);
            if ($permit instanceof DemandWait) {
                app(DemandQueue::class)->cancel($tenant, $operation->operationId, $ticket->demandId);
                throw new AdmissionRejected('fixture_reservation_deferred');
            }

            return [$input, $permit, $wire, $operation, $target];
        });
    }
}
