<?php

declare(strict_types=1);

use Tests\Support\Postgres\ConcurrentTransactions;

test('конкурентный первый unknown сохраняет обе публикации и один scoped incident', function (): void {
    $result = ConcurrentTransactions::execute(<<<'PHP'
\Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
$registry = null;
$directory = sys_get_temp_dir().'/unknown-mapping-'.bin2hex(random_bytes(8));
mkdir($directory);
try {
    \Tests\Support\ProcessCore\DetailFixtures::bind();
    $registry = \App\Models\Procurement\Platform\Registry::factory()->eis()->create();
    // Two existing identities with real work budgets; the new raw status has no mapping.
    \Tests\Support\ProcessCore\DetailFixtures::work($registry, 'unknown-race-a');
    \Tests\Support\ProcessCore\DetailFixtures::work($registry, 'unknown-race-b');
    $workers = [];
    foreach (['a', 'b'] as $name) {
        $workers[$name] = '$directory='.var_export($directory, true).'; $name='.var_export($name, true).'; $registryId='.$registry->id.';'.<<<'WORKER'
        $reached = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$reached, $directory, $name): void {
            if ($reached || !str_starts_with($query->sql, 'select')
                || !str_contains($query->sql, 'prc_status_mappings')
                || !in_array('concurrent-unmapped', $query->bindings, true)) {
                return;
            }
            // Both exact SELECTs have completed before either INSERT is allowed.
            $reached = true;
            touch($directory.'/'.$name);
            $deadline = hrtime(true) + 10_000_000_000;
            while (!is_file($directory.'/a') || !is_file($directory.'/b')) {
                if (hrtime(true) > $deadline) {
                    throw new \RuntimeException('Unknown mapping barrier timed out.');
                }
                usleep(1000);
            }
        });
        $registry = \App\Models\Procurement\Platform\Registry::query()->findOrFail($registryId);
        $snapshot = \Tests\Support\Procurement\SnapshotFactory::make($registry, [
            'registryNumber' => 'unknown-race-'.$name,
            'sourceStage' => 'concurrent-unmapped',
        ]);
        // No retry/catch in the publication: a 23505/25P02 must fail this regression.
        $id = \Illuminate\Support\Facades\DB::transaction(function () use ($snapshot): int {
            $p = app(\App\Services\Procurement\Acquisition\SnapshotFinalizer::class)->ingest($snapshot);
            app(\App\Services\Procurement\Acquisition\UnmappedStatusReporter::class)->record($p);

            return $p->id;
        });

        return ['id' => $id, 'barrier' => $reached,
            'backend' => \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'transaction_level' => \Illuminate\Support\Facades\DB::transactionLevel()];
WORKER;
    }
    $results = \Tests\Support\Postgres\ConcurrentTransactions::concurrently($workers);
    $db = \Illuminate\Support\Facades\DB::connection();
    return [
        'workers' => $results,
        'statuses' => $db->table('prc_procurements')->where('registry_id', $registry->id)->orderBy('source_identity_value')->pluck('status')->all(),
        'revisions' => $db->table('prc_procurements')->where('registry_id', $registry->id)->orderBy('source_identity_value')->pluck('revision_count')->all(),
        'history' => $db->table('prc_revisions')->whereIn('procurement_id', array_column($results, 'id'))->count(),
        'status_events' => $db->table('prc_events')->whereIn('procurement_id', array_column($results, 'id'))->where('event_type', 'status_changed')->where('new_value', 'unknown')->count(),
        'mappings' => $db->table('prc_status_mappings')->where('registry_id', $registry->id)->where('source_stage', 'concurrent-unmapped')->count(),
        'incidents' => $db->table('prc_process_incidents')->where('kind', 'unmapped_status')->count(),
        'works' => $db->table('prc_process_work')->count(),
        'item_failures' => (int) $db->table('prc_process_work')->sum('item_failures'),
        'handler_faults' => $db->table('prc_process_attempts')->whereNotNull('fault_signature')->count(),
    ];
} finally {
    \Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
    \Illuminate\Support\Facades\DB::statement('TRUNCATE prc_procurements CASCADE');
    if ($registry !== null) {
        \Illuminate\Support\Facades\DB::table('prc_status_mappings')->where('registry_id', $registry->id)->delete();
        $registry->delete();
    }
    foreach (glob($directory.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}
PHP);

    expect($result['workers']['a']['barrier'])->toBeTrue()
        ->and($result['workers']['b']['barrier'])->toBeTrue()
        ->and($result['workers']['a']['backend'])->not->toBe($result['workers']['b']['backend'])
        ->and($result['workers']['a']['id'])->not->toBe($result['workers']['b']['id'])
        ->and($result['workers']['a']['transaction_level'])->toBe(0)
        ->and($result['workers']['b']['transaction_level'])->toBe(0);
    expect($result)->toMatchArray([
        'statuses' => ['unknown', 'unknown'], 'revisions' => [2, 2], 'history' => 4,
        'status_events' => 2, 'mappings' => 1, 'incidents' => 1,
        'works' => 2, 'item_failures' => 0, 'handler_faults' => 0,
    ]);
})->group('unknown-status-mapping');
