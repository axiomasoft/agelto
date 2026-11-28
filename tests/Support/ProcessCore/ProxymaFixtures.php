<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Models\Procurement\Proxyma\CutoverJournal;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Services\Procurement\Proxyma\Migration\CutoverFence;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\TestEnvironmentGuard;

final class ProxymaFixtures
{
    public static function clean(): void
    {
        PermitFixtures::clean();
        // RefreshDatabase заменяет transaction manager после boot package scopes.
        app()->forgetScopedInstances();
        DB::connection()->setTransactionManager(app('db.transactions'));
        ExecutionFixtures::truncateLedger();
        DB::statement('TRUNCATE prc_proxyma_owner_links, prc_proxyma_binding_links, prc_proxyma_profile_links, prc_proxyma_target_links');
    }

    public static function activate(): void
    {
        ProxymaUpgradeFixtures::package();
        CutoverFence::install(DB::connection());
        $id = LedgerFixtures::uuid()->value;
        DB::table((new CutoverJournal)->getTable())->insert(['id' => $id, 'source_revision' => 'fixture-legacy',
            'target_revision' => 'fixture-proxyma', 'phase' => 'switched', 'generation' => 2]);
        DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->update(['authority' => 'proxyma', 'generation' => 2, 'cutover_id' => $id]);
    }

    public static function resetAuthority(): void
    {
        TestEnvironmentGuard::assertBootstrapTestDatabase(app());
        if (getenv('PROCESS_CORE_TEST') !== '1') {
            throw new \LogicException('Authority cleanup requires the isolated rig.');
        }
        // Только disposable fixture: runtime rollback никогда не удаляет marker/journal.
        $db = DB::connection();
        // Fence снимается только в guarded disposable fixture, никогда в runtime rollback.
        if ($db->getSchemaBuilder()->hasTable('pxm_permits')) {
            $db->statement('DROP TRIGGER IF EXISTS prc_pxm_first_claim ON pxm_permits');
        }
        $journal = (new CutoverJournal)->getTable();
        $authority = (new NetworkAuthority)->getTable();
        DB::transaction(function () use ($journal, $authority): void {
            DB::statement("ALTER TABLE {$journal} DISABLE TRIGGER USER");
            DB::statement("ALTER TABLE {$authority} DISABLE TRIGGER USER");
            DB::statement("TRUNCATE {$journal}, {$authority} CASCADE");
            DB::statement("ALTER TABLE {$journal} ENABLE TRIGGER USER");
            DB::statement("ALTER TABLE {$authority} ENABLE TRIGGER USER");
            DB::table($authority)->insert(['id' => 'network', 'authority' => 'legacy', 'generation' => 1,
                'paused' => false, 'legacy_frozen' => false]);
        });
    }

    public static function locked(\Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            $scope = app(ConnectionScope::class);
            $tx = new LockedTransactionContext(DB::connection()->getName(), LedgerFixtures::uuid()->value, hrtime(true), DB::transactionLevel());
            $scope->register($tx);

            return $callback($tx);
        });
    }
}
