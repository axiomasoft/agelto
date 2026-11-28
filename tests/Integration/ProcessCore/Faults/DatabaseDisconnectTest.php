<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcceptanceScenario;

pest()->group('claim-recovery-and-invariants', 'provider-and-worker-recovery', 'run-state-reduction', 'faults');

test('реальная потеря PostgreSQL connection внутри finalization откатывает результат и journal до recovery того же work', function (): void {
    $scenario = new AcceptanceScenario;
    try {
        [$id] = $scenario->documents(1);
        touch($scenario->root.'/arm-finalize-before-commit');
        $scenario->start();
        $marker = $scenario->awaitBoundary('finalize-before-commit');
        expect($marker['work'])->toBe($id)->and($marker['transaction_level'])->toBeGreaterThan(0);
        $terminated = DB::selectOne('SELECT pg_terminate_backend(pid) AS terminated FROM pg_stat_activity
            WHERE pid=? AND datname=current_database() AND usename=current_user AND pid<>pg_backend_pid()', [$marker['backend_pid']]);
        expect($terminated?->terminated)->toBeTrue();
        touch($scenario->root.'/release');
        expect(DB::table('prc_process_transitions')->where('work_id', $id)->where('new_state', 'succeeded')->count())->toBe(0);
        $until = hrtime(true) + 50_000_000_000;
        do {
            $scenario->recover();
            $scenario->tick();
            if (DB::table('prc_process_work')->where('id', $id)->value('state') === 'succeeded') {
                break;
            }
            usleep(100_000);
        } while (hrtime(true) < $until);
        $scenario->drain(40);
        expect(DB::table('prc_process_transitions')->where('work_id', $id)->where('new_state', 'succeeded')->count())->toBe(1)
            ->and(DB::table('prc_process_attempts')->whereNotNull('fault_signature')->count())->toBe(0)
            ->and(DB::table('prc_process_work')->sum('item_failures'))->toBe(0)
            ->and(DB::table('prc_process_reservations')->count())->toBe(0);
    } finally {
        $scenario->close();
    }
});
