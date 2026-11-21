<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Services\Procurement\Process\RecoveryReconciler;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;

pest()->group('claim-recovery-and-invariants', 'work-controls-and-fences', 'daemon-reconciliation');

/** @return list<string> IDs ordered by lease expiry. */
function expiredRunningCursorCandidates(int $total): array
{
    $limits = ['max_outstanding' => 200, 'max_running' => 200, 'low_watermark' => 199, 'refill_max' => 200];
    FinalizeFixtures::authority([StageCode::Listing], [
        'acquisition' => $limits,
        'acquisition.listing' => $limits,
    ]);

    for ($index = 0; $index < $total; $index++) {
        FinalizeFixtures::running(ExecutionFixtures::listingWork());
    }
    $ordered = DB::table('prc_process_work')->where('state', 'running')->orderBy('id')->pluck('id');
    expect($ordered)->toHaveCount($total);
    foreach ($ordered as $index => $id) {
        FinalizeFixtures::expire(new WorkId(new ProcessUuid((string) $id)), 3_600 - $index);
    }

    return DB::table('prc_process_work')
        ->where('state', 'running')
        ->orderBy('lease_until')
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

test('сбой inspect у самой старой expired running не ждёт wrap-around, пока следующая страница курсора полная', function (): void {
    $expired = expiredRunningCursorCandidates(RecoveryReconciler::BATCH * 2);
    $oldestId = $expired[0];
    $newestId = $expired[array_key_last($expired)];
    $oldestInspections = 0;
    $resolver = FixtureProviderRecoveryResolver::install();
    $resolver->afterInspect = static function (WorkId $workId) use ($oldestId, &$oldestInspections): void {
        if ($workId->value->value === $oldestId && ++$oldestInspections === 1) {
            throw new DependencyUnavailable('storage');
        }
    };
    $reconciler = app(RecoveryReconciler::class);

    expect($reconciler->recoverExpiredRunning())->toBe(RecoveryReconciler::BATCH - 1)
        ->and(DB::table('prc_process_work')->where('state', 'running')->count())->toBe(RecoveryReconciler::BATCH + 1)
        ->and(DB::table('prc_process_work')->where('id', $oldestId)->value('state'))->toBe('running')
        ->and(DB::table('prc_process_work')->where('id', $newestId)->value('state'))->toBe('running')
        ->and($oldestInspections)->toBe(1)
        ->and($reconciler->recoverExpiredRunning())->toBe(RecoveryReconciler::BATCH + 1)
        ->and(DB::table('prc_process_work')->where('id', $oldestId)->value('state'))->toBe('ready')
        ->and(DB::table('prc_process_work')->where('id', $newestId)->value('state'))->toBe('ready')
        ->and($oldestInspections)->toBe(2)
        ->and(DB::table('prc_process_work')->where('state', 'running')->count())->toBe(0);
});

test('повторный сбой всей страницы оставляет место для повтора следующей страницы', function (): void {
    $batch = RecoveryReconciler::BATCH;
    $expired = expiredRunningCursorCandidates($batch * 3);
    $resolver = FixtureProviderRecoveryResolver::install();
    $reconciler = app(RecoveryReconciler::class);

    $resolver->storageDown = true;
    expect($reconciler->recoverExpiredRunning())->toBe(0)
        ->and($resolver->inspections)->toBe($batch);

    $resolver->storageDown = false;
    $resolver->afterInspect = static function (): never {
        throw new RuntimeException('Ошибка проверки кандидата');
    };
    expect($reconciler->recoverExpiredRunning())->toBe(0)
        ->and($resolver->inspections)->toBe($batch * 3);

    $resolver->afterInspect = null;
    expect($reconciler->recoverExpiredRunning())->toBe($batch * 2)
        ->and(DB::table('prc_process_work')->where('state', 'running')->orderBy('lease_until')->pluck('id')->all())
        ->toBe(array_slice($expired, 0, $batch))
        ->and($reconciler->recoverExpiredRunning())->toBe($batch)
        ->and(DB::table('prc_process_work')->where('state', 'running')->count())->toBe(0);
});
