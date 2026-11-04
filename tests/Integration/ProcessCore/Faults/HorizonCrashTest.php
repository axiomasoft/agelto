<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcceptanceScenario;
use Tests\Support\ProcessCore\ExecutionFixtures;

pest()->group('delivery-and-daemon-lifecycle', 'claim-recovery-and-invariants', 'provider-and-worker-recovery', 'faults');

test('SIGKILL конкретного Horizon worker восстанавливает тот же work ID с одной accepted финализацией', function (string $boundary): void {
    $scenario = new AcceptanceScenario;
    try {
        [$id] = $scenario->documents(1);
        touch($scenario->root.'/arm-'.$boundary);
        $scenario->start();
        $marker = $scenario->awaitBoundary($boundary);
        expect($marker['work'])->toBe($id)->and($marker['pid'])->not->toBe(getmypid());
        if ($boundary === 'finalize-before-commit') {
            expect($marker['transaction_level'])->toBeGreaterThan(0);
        }
        expect(posix_kill($marker['pid'], SIGKILL))->toBeTrue();
        $work = new WorkId(new ProcessUuid($id));
        $stateAfterKill = ExecutionFixtures::work($work)->state;
        if ($boundary === 'mid-range-checkpoint') {
            // Commit releases the slot; another worker may already own the continuation.
            expect($stateAfterKill)->toBeIn(['ready', 'admitted', 'running', 'succeeded'])
                ->and(DB::table('prc_process_transitions')->where('work_id', $id)
                    ->where('previous_state', 'running')->where('new_state', 'ready')->count())->toBeGreaterThanOrEqual(1);
        } else {
            expect($stateAfterKill)->toBe($boundary === 'commit-before-ack' ? 'succeeded' : ($boundary === 'before-claim' ? 'admitted' : 'running'));
        }
        // Real short DB lease/visibility budgets. Do not rewrite clocks/lease timestamps.
        $until = hrtime(true) + 50_000_000_000;
        do {
            $scenario->recover();
            $scenario->tick();
            if (ExecutionFixtures::work($work)->state === 'succeeded') {
                break;
            }
            usleep(100_000);
        } while (hrtime(true) < $until);
        $scenario->drain(40);
        expect(ExecutionFixtures::work($work)->state)->toBe('succeeded')
            ->and(DB::table('prc_process_transitions')->where('work_id', $id)->where('new_state', 'succeeded')->count())->toBe(1)
            ->and(DB::table('prc_process_reservations')->count())->toBe(0)
            ->and(DB::table('prc_process_attempts')->where('work_id', $id)->whereNotNull('fault_signature')->count())->toBe(0);
        if ($boundary === 'mid-range-checkpoint') {
            $executions = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($scenario->root.'/executions.jsonl', FILE_IGNORE_NEW_LINES));
            $positions = array_column($executions, 'position');
            sort($positions);
            expect($positions)->toBe([0, 1, 2]);
        }
    } finally {
        $scenario->close();
    }
})->with(['before-claim', 'after-lease', 'before-send', 'sent-intent-before-wire', 'after-bytes',
    'finalize-before-commit', 'mid-range-checkpoint', 'commit-before-ack']);

test('SIGTERM Horizon разрешает завершить уже полученные bytes и остановить все его workers', function (): void {
    $scenario = new AcceptanceScenario;
    try {
        [$id] = $scenario->documents(1);
        touch($scenario->root.'/arm-after-bytes');
        $scenario->start();
        $marker = $scenario->awaitBoundary('after-bytes');
        expect($marker['work'])->toBe($id)->and(posix_kill($marker['pid'], SIGTERM))->toBeTrue();
        touch($scenario->root.'/release');
        $scenario->drain();
        $scenario->stop();
        expect(DB::table('prc_process_transitions')->where('work_id', $id)->where('new_state', 'succeeded')->count())->toBe(1)
            ->and(DB::table('prc_process_attempts')->whereNotNull('fault_signature')->count())->toBe(0);
    } finally {
        $scenario->close();
    }
});
