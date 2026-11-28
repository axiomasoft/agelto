<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Models\Procurement\Proxyma\TargetLink;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\BindingCommands;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Contracts\DestinationResolver;
use Proxyma\Contracts\PolicyCommands;
use Proxyma\Contracts\SecretResolver;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Infrastructure\Cache\PolicyResolutionCache;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Security\StandaloneCatalogMutationFence;

/** Только isolated fixture: реальные package schema, ledger, cURL и отдельные worker sessions. */
final class ProxymaProviderFixtures
{
    public function __construct(public object $provider, ?int $proxyPort = null, ?\Closure $beforeActivate = null)
    {
        self::guard();
        $package = getenv('PROXYMA_CANDIDATE_CORE') ?: base_path('vendor/axiomasoft/proxyma');
        if (! DB::table('migrations')->where('migration', '2026_11_09_000001_create_pxm_authority_tables')->exists()) {
            foreach (DB::select("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname='public' AND p.proname LIKE 'pxm\\_%' ESCAPE '\\' AND p.prorettype='trigger'::regtype AND p.pronargs=0") as $function) {
                $name = DB::connection()->getQueryGrammar()->wrap($function->proname);
                DB::statement("DROP FUNCTION {$name}() CASCADE");
            }
        }
        $exit = Artisan::call('migrate', ['--path' => [$package.'/database/migrations', $package.'/src/Laravel/Migrations'],
            '--realpath' => true, '--force' => true]);
        if ($exit !== 0) {
            throw new \RuntimeException('Package fixture migrations failed.');
        }
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'pxm\_%' ESCAPE '\\'");
        $names = array_map(static fn ($row): string => '"'.$row->tablename.'"', $tables);
        if ($names !== []) {
            DB::statement('TRUNCATE '.implode(', ', $names).' CASCADE');
        }
        DB::table((new TargetLink)->getTable())->delete();
        ProxymaFixtures::resetAuthority();
        self::configure();
        $tenant = TenantKey::system();
        $commands = app(CatalogCommands::class);
        $route = Uuid::random();
        $profile = Uuid::random();
        $target = new Uuid($provider->target->value);
        $origin = CanonicalOrigin::fromUrl($provider->wire->allowedOrigin);
        $actor = fn (): CatalogMutationActor => new CatalogMutationActor(new ActorRef('worker', 'fixture-provider'), 'isolated provider fixture', 0, Uuid::random()->value);
        // System budgets требуют существующий authority head до reserve/claim.
        app(PolicyCommands::class)->update(new UpdatePolicyCommand(Uuid::random(), $tenant, PolicyScope::Global,
            TenantKey::SYSTEM, '{"schema_version":1,"operations":{}}', $actor()));
        $endpoint = $proxyPort === null ? null : Uuid::random();
        if ($endpoint !== null) {
            $commands->upsertEndpoint(new UpsertEndpointCommand($endpoint, $tenant, ProxyProtocol::Http,
                'fixture.test', $proxyPort, new CredentialRef('fixture:proxy', 1), $actor()));
        }
        $commands->upsertRoute(new UpsertRouteCommand($route, $tenant, 'fixture-provider', $endpoint === null ? RouteKind::Direct : RouteKind::Proxy, $endpoint, true,
            EgressIdentity::explicit('nat:fixture-provider'), null, false, $actor()));
        $commands->upsertProfile(new UpsertRouteProfileCommand($profile, $tenant, 'fixture-provider', [$route], 'uniform_random', $actor()));
        $commands->upsertTarget(new UpsertTargetCommand($target, $tenant, 'fixture-provider', $origin, 'default', $actor(), [
            new BudgetConstraint($origin->budgetScopeKey(), 100_000, 1, 2, true),
            new BudgetConstraint($origin->routeScopeKey($route), 100_000, 1, 2, true),
        ]));
        foreach (['route_health', 'route_target_health', 'profile_health', 'target_health'] as $name) {
            DB::table(PackageSchema::tables()[$name])->where('tenant_key', $tenant->value)->update(
                $name === 'target_health' ? ['state' => 'closed', 'availability' => 'available'] : ['state' => 'closed']);
        }
        $source = DB::table('prc_source_targets')->where('id', $provider->target->value)->sole();
        DB::table((new TargetLink)->getTable())->insert(['source_target_id' => $source->id, 'tenant_key' => TenantKey::SYSTEM,
            'target_id' => $target->value, 'source_revision' => $source->policy_version]);
        app(BindingCommands::class)->bindPolicy(new BindSubjectPolicyCommand(new SubjectRef($tenant, 'provider', 'fixture-provider'),
            $profile, 'fixture-provider', $actor(), panelKey: 'agelto.provider', slotKey: 'llm'));
        $notBefore = DB::table('prc_network_budgets')->whereNotNull('not_before')->max('not_before');
        if ($notBefore !== null) {
            DB::table(PackageSchema::tables()['budgets'])->update(['tat' => $notBefore, 'send_tat' => $notBefore]);
        }
        $beforeActivate?->__invoke();
        ProxymaFixtures::activate();
    }

    public static function installTarget(string $target, string $origin): void
    {
        new self((object) ['target' => new ProcessUuid($target),
            'wire' => (object) ['allowedOrigin' => $origin]]);
    }

    public static function configure(): void
    {
        self::guard();
        // Bootstrap definitions могли захватить Connection до worker DB::purge().
        app()->forgetScopedInstances();
        app()->instance(CatalogMutationFence::class, new StandaloneCatalogMutationFence);
        DB::connection()->setTransactionManager(app('db.transactions'));
        config(['proxyma.wire.digest_key' => 'fixture-only-provider-hmac-key-32-bytes',
            'proxyma.policy.project_defaults' => ['route.allow_direct' => true]]);
        app(PolicyResolutionCache::class)->invalidateGlobal();
        app()->instance(TenantContextResolver::class, new class implements TenantContextResolver
        {
            public function resolve(): TenantContext
            {
                return new TenantContext(TenantKey::system(), new ActorRef('worker', 'fixture-provider'));
            }
        });
        app()->instance(Authorizer::class, new class implements Authorizer
        {
            public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
            {
                if (! $tenant->isSystem() || $actor->id !== 'fixture-provider') {
                    throw new \RuntimeException('Foreign fixture tenant.');
                }
            }
        });
        $source = DB::table('prc_source_targets')->where('provider_key', 'fixture-provider')->sole();
        $origin = CanonicalOrigin::fromUrl($source->canonical_origin);
        $dns = new DnsResolver(app(ConnectionScope::class), static fn (): array => ['127.0.0.1']);
        app()->instance(ProxyResolver::class, new ProxyResolver($dns, ['fixture.test' => ['127.0.0.1']]));
        app()->instance(SecretResolver::class, new class implements SecretResolver
        {
            public function resolve(CredentialRef $credential, TenantKey $tenant): ?ResolvedSecret
            {
                return $tenant->isSystem() && $credential->reference === 'fixture:proxy'
                    ? new ResolvedSecret($credential, 'fixture', 'rejected-fixture-password') : null;
            }
        });
        app()->instance(DestinationResolver::class, new DestinationPolicy($dns,
            [$source->id => [$origin->host => ['127.0.0.1']]], [$origin->port]));
    }

    public function worker(string $action): string
    {
        return str_replace('$gate=', '\\Tests\\Support\\ProcessCore\\ProxymaProviderFixtures::configure();$gate=', $this->provider->worker($action));
    }

    private static function guard(): void
    {
        if (! app()->environment('testing') || getenv('PROCESS_CORE_TEST') !== '1'
            || DB::selectOne('SELECT current_database() AS db')->db !== 'agelto_test') {
            throw new \RuntimeException('Package provider fixtures require the isolated agelto_test rig.');
        }
    }
}
