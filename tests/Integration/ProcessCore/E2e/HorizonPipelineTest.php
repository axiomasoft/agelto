<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcceptanceScenario;
use Tests\Support\ProcessCore\ExecutionFixtures;

pest()->group('delivery-and-daemon-lifecycle', 'admission-fairness', 'sustained-workload', 'bounded-network-demand', 'e2e');

test('настоящий Horizon исполняет Redis delivery и три checkpoint slices двух платформ через PostgreSQL FIFO и local HTTP', function (): void {
    $scenario = new AcceptanceScenario;
    try {
        $ids = $scenario->documents(4, old: true);
        $scenario->start();
        $scenario->drain();
        expect(DB::table('prc_process_work')->whereIn('id', $ids)->where('state', 'succeeded')->count())->toBe(4)
            ->and(DB::table('prc_process_transitions')->where('new_state', 'succeeded')->count())->toBe(4)
            ->and(DB::table('prc_process_attempts')->count())->toBeGreaterThanOrEqual(12)
            ->and(DB::table('prc_process_attempts')->whereNotNull('fault_signature')->count())->toBe(0)
            ->and(DB::table('prc_process_work')->sum('item_failures'))->toBe(0)
            ->and(DB::table('prc_request_attempts')->whereNotNull('finished_at')->count())->toBe(12)
            ->and($scenario->wire->receipts())->toHaveCount(12)
            ->and(ExecutionFixtures::laneCounts('analysis'))->toBe(['outstanding' => 0, 'running' => 0])
            ->and(DB::table('prc_network_demands')->where('state', 'queued')->count())->toBe(0);
        $executions = file($scenario->root.'/executions.jsonl', FILE_IGNORE_NEW_LINES);
        expect($executions)->toHaveCount(12)
            ->and(array_unique(array_column(array_map(fn ($line) => json_decode($line, true), $executions), 'pid')))->not->toContain(getmypid());
    } finally {
        $scenario->close();
    }
});
