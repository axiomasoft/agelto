<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Models\Llm\Provider;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Procurement\Proxyma\Migration\CutoverJournal;
use App\Services\Procurement\Proxyma\Migration\Inventory;
use App\Services\Procurement\Proxyma\Migration\TransferCatalog;
use App\Services\Procurement\Proxyma\Migration\TransferState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Execution\Uuid;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\TestEnvironmentGuard;

final class ProxymaUpgradeFixtures
{
    public static function baseline(): void
    {
        TestEnvironmentGuard::assertBootstrapTestDatabase(app());
        if (getenv('PROCESS_CORE_TEST') !== '1' || DB::selectOne('SELECT current_database() AS db')->db !== 'agelto_test' || DB::transactionLevel() === 0) {
            throw new \LogicException('Upgrade proof requires the guarded serial rig and rollback transaction.');
        }
        $baseline = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/proxyma-baseline-schema.json')), true, flags: JSON_THROW_ON_ERROR);
        $paths = [];
        foreach ($baseline['migrations'] as $path => $sha) {
            if (hash_file('sha256', base_path($path)) !== $sha) {
                throw new \LogicException('Pinned baseline migration drift: '.$path);
            }
            $paths[] = base_path($path);
        }
        // Метаданные pg_partman тоже принадлежат rollback fixture, а не dev/shared DB.
        DB::table('partman.part_config')->delete();
        DB::getSchemaBuilder()->dropAllTables();
        DB::getSchemaBuilder()->create('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        self::apply($paths);
        if (DB::getSchemaBuilder()->hasTable('prc_network_authorities') || DB::getSchemaBuilder()->hasTable('pxm_routes')) {
            throw new \LogicException('Baseline must precede every Proxyma migration.');
        }
    }

    public static function forward(): void
    {
        foreach (['2026_11_11_100000_create_prc_proxyma_adapter_links.php', '2026_11_11_100001_create_prc_proxyma_transfer_records.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        self::package();
        (require database_path('migrations/2026_11_11_100002_add_prc_proxyma_cutover_fences.php'))->up();
    }

    public static function package(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('pxm_routes')) {
            foreach (DB::select("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public'
                AND p.proname LIKE 'pxm\_%' AND p.prorettype='trigger'::regtype AND p.pronargs=0") as $function) {
                DB::statement('DROP FUNCTION '.$function->proname.'() CASCADE');
            }
        }
        $root = getenv('PROXYMA_CANDIDATE_CORE') ?: base_path('vendor/axiomasoft/proxyma');
        $paths = [...glob($root.'/database/migrations/*.php'), ...glob($root.'/src/Laravel/Migrations/*.php')];
        usort($paths, static fn (string $a, string $b): int => basename($a) <=> basename($b));
        self::apply($paths);
    }

    /** @param list<string> $paths */
    private static function apply(array $paths): void
    {
        // Laravel 13 migrator выбирает отдельное write connection. DDL fixture должен оставаться
        // на том же PDO, что и внешняя rollback transaction, включая migration records.
        foreach ($paths as $path) {
            $name = basename($path, '.php');
            if (DB::table('migrations')->where('migration', $name)->exists()) {
                continue;
            }
            (require $path)->up();
            DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);
        }
    }

    /** @return array{Inventory, TransferCatalog, TransferState, string} */
    public static function services(): array
    {
        $inventory = new Inventory(DB::connection(), str_repeat('isolated-upgrade-hmac-key', 2));
        $journal = new CutoverJournal($inventory);

        return [$inventory, new TransferCatalog($journal), new TransferState($journal), Uuid::random()->value];
    }

    /** @return array<string, mixed> */
    public static function populate(): array
    {
        $f = new PermitFixtures;
        $crawler = AcquisitionProfileFixtures::create();
        DB::table('prc_network_route_profile_members')->insert(['profile_id' => $crawler->route_profile_id, 'route_id' => $f->route->routeId->value]);
        DB::table('prc_network_route_profiles')->where('id', $crawler->route_profile_id)->update(['policy_version' => 4]);
        $proxies = [HistoricalNetworkFixtures::proxy(), HistoricalNetworkFixtures::proxy()];
        $map = [];
        foreach ($proxies as $proxy) {
            DB::table('prc_network_routes')->where('id', $proxy->route_id)->update(['egress_id' => 'nat:shared', 'credential_version' => 7, 'secret_ref' => 'fixture:opaque-credential']);
            $map[$proxy->id] = ['endpoint_id' => Uuid::random()->value, 'protocol' => 'http'];
            DB::table('prc_network_route_profile_members')->insert(['profile_id' => $crawler->route_profile_id, 'route_id' => $proxy->route_id]);
        }
        DB::table('prc_network_budgets')->update(['tat' => '2026-12-01 00:00:00+00', 'send_tat' => '2026-12-01 00:00:01+00',
            'not_before' => '2026-12-01 00:00:02+00', 'policy_version' => 3]);
        DB::table('prc_route_target_health')->update(['health_epoch' => 9, 'state_version' => 5, 'consecutive_failures' => 4, 'recent_openings' => 2, 'state' => 'open', 'open_until' => '2026-12-01 00:00:00+00']);
        $operation = Uuid::random()->value;
        $demand = Uuid::random()->value;
        $permit = Uuid::random()->value;
        $request = Uuid::random()->value;
        Provider::query()->where('code', 'fixture-provider')->first() ?? Provider::factory()->create(['code' => 'fixture-provider', 'is_active' => true]);
        $finance = app(FinancialReservationRepository::class);
        $finance->configureAccount('fixture-account', 'USD', 1, null, 'fixture', 'isolated upgrade');
        $period = $finance->openPeriod('fixture-account', 'USD', '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z', '100');
        $authorization = app(RequestRepository::class)->authorize(FinancialFixtures::genericPlan('upgrade'), Uuid::random()->value, 1, $period);
        $request = $authorization->physicalCallId;
        DB::table('prc_outbound_operations')->insert(['id' => $operation, 'operation_key' => Uuid::random()->value, 'kind' => 'legacy_bridge',
            'caller_code' => 'fixture.upgrade', 'state' => 'closed', 'owner_token' => Uuid::random()->value, 'generation' => 2,
            'lease_until' => '2026-01-01', 'deadline_at' => '2026-01-02', 'created_at' => '2026-01-01', 'finished_at' => '2026-01-01', 'policy_hash' => DB::raw("decode(repeat('ab',32),'hex')")]);
        DB::table('prc_network_demands')->insert(['id' => $demand, 'physical_request_id' => $request, 'operation_id' => $operation, 'operation_generation' => 2,
            'origin_budget_id' => DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->sole()->id, 'route_id' => $f->route->routeId->value, 'target_id' => $f->target->value,
            'traffic_class' => 'detail', 'purpose' => 'ordinary', 'registered_at' => '2026-01-01', 'renewed_at' => '2026-01-01',
            'expires_at' => '2026-01-02', 'operation_deadline' => '2026-01-02', 'state' => 'granted']);
        DB::table('prc_network_permits')->insert(['id' => $permit, 'demand_id' => $demand, 'slot_at' => '2026-01-01', 'expires_at' => '2026-01-02',
            'authorized_at' => '2026-01-01', 'state' => 'released', 'policy_versions' => '{}', 'policy_schema_version' => 1,
            'health_epochs' => '{}', 'health_epochs_schema_version' => 1, 'created_at' => '2026-01-01']);
        DB::table('prc_network_permit_scopes')->insert(['permit_id' => $permit, 'budget_id' => DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->sole()->id]);
        DB::table('prc_request_attempts')->insert(['request_id' => $request, 'demand_id' => $demand, 'request_seq' => 1, 'target_id' => $f->target->value,
            'route_id' => $f->route->routeId->value, 'started_at' => '2026-01-01', 'sent_at' => '2026-01-01', 'finished_at' => '2026-01-01',
            'phase' => 'finished', 'outcome' => 'response', 'epochs' => '{}', 'epochs_schema_version' => 1]);
        $health = DB::table('prc_route_target_health')->where('route_id', $f->route->routeId->value)->sole();
        $retired = Uuid::random()->value;
        $target = (array) DB::table('prc_source_targets')->where('id', $f->target->value)->sole();
        DB::table('prc_source_targets')->insert(array_replace($target, ['id' => $retired, 'target_key' => 'retired', 'retired_at' => '2026-01-01']));
        DB::table('prc_adapter_health')->insert(['id' => Uuid::random()->value, 'platform_id' => $target['platform_id'], 'capability' => 'detail',
            'adapter_version' => 'fixture', 'contract_version' => 'fixture', 'state' => 'held', 'health_epoch' => 11, 'state_version' => 7,
            'reason_code' => 'contract_mismatch', 'recent_openings' => 0]);
        foreach ([['network_transport', false, 9], ['network_transport', true, 8], ['adapter_validation', false, 9]] as [$kind, $stale, $epoch]) {
            $id = Uuid::random()->value;
            $key = 'prc_route_target_health:'.$health->id;
            DB::table('prc_observations')->insert(['observation_id' => $id, 'request_id' => $request, 'kind' => $kind, 'reason_code' => 'fixture',
                'input_hash' => DB::raw("decode(repeat('ab',32),'hex')"), 'generation' => 2, 'epochs' => json_encode([$key => $epoch]), 'epochs_schema_version' => 1,
                'stale' => $stale, 'details' => '{"route_target":"failure","independence_group":"fixture-independent"}', 'details_schema_version' => 1, 'occurred_at' => DB::raw('clock_timestamp()')]);
            DB::table('prc_observation_applications')->insert(['observation_id' => $id, 'projection_key' => $key, 'reducer_version' => 1, 'applied_at' => DB::raw('clock_timestamp()')]);
        }

        return compact('f', 'crawler', 'map', 'permit', 'request', 'operation', 'health', 'retired');
    }
}
