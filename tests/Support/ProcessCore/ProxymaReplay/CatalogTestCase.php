<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\ProxymaReplay;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PDO;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Http\TlsTrust;
use Proxyma\Infrastructure\Security\StandaloneCatalogMutationFence;
use Proxyma\Laravel\ProxymaServiceProvider;
use Proxyma\Tests\Support\TenantFixtures;
use RuntimeException;
use Tests\Support\TestEnvironmentGuard;

require_once __DIR__.'/TenantFixtures.php';

trait NativePackageReplay
{
    use TenantFixtures;

    protected Connection $db;

    protected string $fixtureSchema;

    private static ?string $migratedReplaySchema = null;

    protected TenantKey $tenant;

    private string $replayOriginalConnection;

    /** @var array<string, array{process: string|false, env: mixed, server: mixed, has_env: bool, has_server: bool}> */
    private array $replayEnvironment = [];

    // Внешняя rollback transaction скрыла бы отдельную replay schema от workers.
    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function setUpNativePackageReplay(): void
    {
        TestEnvironmentGuard::assertBootstrapTestDatabase($this->app);
        if (getenv('PROCESS_CORE_TEST') !== '1' || DB::selectOne('SELECT current_database() AS db')->db !== 'agelto_test') {
            throw new RuntimeException('Package replay requires the isolated agelto_test rig.');
        }
        $this->replayOriginalConnection = DB::getDefaultConnection();
        $configuration = DB::connection()->getConfig();
        $this->fixtureSchema = self::$migratedReplaySchema ??= 'pxm_t_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA IF NOT EXISTS '.$this->fixtureSchema);
        $configuration['search_path'] = $this->fixtureSchema;
        config(['database.connections.proxyma_replay' => $configuration, 'proxyma.connection' => 'proxyma_replay',
            'proxyma.extensions.providers' => [], 'proxyma.extensions.locked_admission_guards' => [],
            'proxyma.wire.digest_key' => 'isolated-consumer-replay-key-at-least-32-bytes']);
        DB::setDefaultConnection('proxyma_replay');
        $this->db = DB::connection('proxyma_replay');
        $this->app->forgetScopedInstances();
        $this->app->register(ProxymaServiceProvider::class, true);
        $this->app->instance(CatalogMutationFence::class, new StandaloneCatalogMutationFence);
        $this->app->bind(OwnerAuthority::class, DatabaseOwnerAuthority::class);
        $this->app->bind(TlsTrustPolicy::class, fn () => new TlsTrust($this->app->make(ConnectionScope::class),
            config('proxyma.tls.origin_bundles', []), config('proxyma.tls.proxy_bundles', [])));
        $this->tenant = new TenantKey('acme');
        $this->useTenant($this->tenant);
        $package = getenv('PROXYMA_CANDIDATE_CORE') ?: base_path('vendor/axiomasoft/proxyma');
        $paths = [...glob($package.'/database/migrations/*.php'), ...glob($package.'/src/Laravel/Migrations/*.php')];
        usort($paths, static fn (string $a, string $b): int => basename($a) <=> basename($b));
        if (! $this->db->getSchemaBuilder()->hasTable('migrations')) {
            $this->db->getSchemaBuilder()->create('migrations', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
            });
        }
        foreach ($paths as $migration) {
            $name = basename($migration, '.php');
            if (! $this->db->table('migrations')->where('migration', $name)->exists()) {
                (require $migration)->up();
                $this->db->table('migrations')->insert(['migration' => $name, 'batch' => 1]);
            }
        }
        foreach ([
            'PROXYMA_REPLAY_DSN' => 'pgsql:host='.$configuration['host'].';port='.$configuration['port'].';dbname=agelto_test',
            'PROXYMA_REPLAY_USER' => $configuration['username'],
            'PROXYMA_REPLAY_PASSWORD' => $configuration['password'],
        ] as $key => $value) {
            $this->replayEnvironment[$key] = ['process' => getenv($key), 'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null, 'has_env' => array_key_exists($key, $_ENV),
                'has_server' => array_key_exists($key, $_SERVER)];
            // Symfony Process пересекает getenv() с $_SERVER: одного putenv для workers недостаточно.
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    protected function tearDownNativePackageReplay(): void
    {
        try {
            if (isset($this->db, $this->replayOriginalConnection, $this->fixtureSchema)) {
                while ($this->db->transactionLevel() > 0) {
                    $this->db->rollBack();
                }
                if (preg_match('/^pxm_t_[a-f0-9]{16}$/D', $this->fixtureSchema) !== 1) {
                    throw new RuntimeException('Invalid replay cleanup schema.');
                }
                $this->db->statement('DROP FUNCTION IF EXISTS '.$this->fixtureSchema.'.clock_timestamp() CASCADE');
                $this->db->statement('DROP TABLE IF EXISTS '.$this->fixtureSchema.'.fixture_clock CASCADE');
                $this->db->statement('DROP FUNCTION IF EXISTS '.$this->fixtureSchema.'.fixture_fail_rotation_audit() CASCADE');
                $this->db->statement('DROP TABLE IF EXISTS '.$this->fixtureSchema.'.fixture_quota_finance CASCADE');
                $tables = $this->db->select("SELECT tablename FROM pg_tables WHERE schemaname=? AND tablename LIKE 'pxm\\_%' ESCAPE '\\'", [$this->fixtureSchema]);
                if ($tables !== []) {
                    $names = array_map(fn ($row): string => $this->db->getQueryGrammar()->wrap($this->fixtureSchema.'.'.$row->tablename), $tables);
                    $this->db->statement('TRUNCATE '.implode(', ', $names).' CASCADE');
                }
                DB::setDefaultConnection($this->replayOriginalConnection);
                $this->db->disconnect();
                DB::purge('proxyma_replay');
            }
        } finally {
            foreach ($this->replayEnvironment as $key => $before) {
                putenv($before['process'] === false ? $key : "{$key}={$before['process']}");
                unset($_ENV[$key], $_SERVER[$key]);
                if ($before['has_env']) {
                    $_ENV[$key] = $before['env'];
                }
                if ($before['has_server']) {
                    $_SERVER[$key] = $before['server'];
                }
            }
        }
    }

    protected function actor(int $revision = 0, ?string $key = null, string $reason = 'fixture change'): CatalogMutationActor
    {
        return new CatalogMutationActor(new ActorRef('operator', 'fixture-operator'), $reason, $revision, $key ?? Uuid::random()->value);
    }

    protected function commands(bool $allow = true): CatalogCommands
    {
        $this->app->instance(Authorizer::class, new class($allow) implements Authorizer
        {
            public function __construct(private bool $allow) {}

            public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
            {
                if (! $this->allow) {
                    throw new RuntimeException('fixture authorization denied');
                }
            }
        });

        return $this->app->make(CatalogCommands::class);
    }

    /** @return array{route: Uuid, profile: Uuid, target: Uuid} */
    protected function provision(): array
    {
        $commands = $this->commands();
        $route = Uuid::random();
        $profile = Uuid::random();
        $target = Uuid::random();
        $commands->upsertRoute(new UpsertRouteCommand($route, $this->tenant, 'direct-main', RouteKind::Direct, null, true, EgressIdentity::explicit('nat:fixture'), null, false, $this->actor()));
        $commands->upsertProfile(new UpsertRouteProfileCommand($profile, $this->tenant, 'default', [$route], 'uniform_random', $this->actor()));
        $commands->upsertTarget(new UpsertTargetCommand($target, $this->tenant, 'fixture-origin', CanonicalOrigin::of('https', 'example.test'), 'default', $this->actor()));

        return compact('route', 'profile', 'target');
    }

    protected function table(string $key): Builder
    {
        return $this->db->table(PackageSchema::tables()[$key]);
    }

    protected function rejectedSql(callable $write, string $sqlstate = '23514'): void
    {
        try {
            $this->db->transaction($write);
            $this->fail('Expected PostgreSQL to reject the write.');
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->errorInfo[0]);
        }
    }

    protected function connectionPdo(): PDO
    {
        $pdo = new PDO(getenv('PROXYMA_REPLAY_DSN'), getenv('PROXYMA_REPLAY_USER'), getenv('PROXYMA_REPLAY_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET search_path TO '.$this->fixtureSchema);

        return $pdo;
    }

    protected function operation(array $catalog): Uuid
    {
        $id = Uuid::random();
        $this->table('operations')->insert(['id' => $id->value, 'tenant_key' => $this->tenant->value,
            'profile_id' => $catalog['profile']->value, 'target_id' => $catalog['target']->value,
            'owner_type' => 'fixture', 'owner_id' => 'owner', 'owner_token' => 'token', 'owner_generation' => 1,
            'deadline' => $this->db->raw("clock_timestamp() + interval '1 minute'"), 'max_physical_attempts' => 3]);

        return $id;
    }
}
