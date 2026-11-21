<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;
use App\Services\Procurement\Process\RecoveryReconciler;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;

pest()->group('claim-recovery-and-invariants', 'work-controls-and-fences', 'daemon-reconciliation');

// Один singleton RecoveryReconciler. Полная вторая страница не должна прятать голову,
// у которой inspect один раз бросил DependencyUnavailable.

test('сбой inspect у самой старой expired running не ждёт wrap-around, пока следующая страница курсора полная', function (): void {
    $limits = ['max_outstanding' => 200, 'max_running' => 200, 'low_watermark' => 199, 'refill_max' => 200];
    FinalizeFixtures::authority([StageCode::Listing], [
        'acquisition' => $limits,
        'acquisition.listing' => $limits,
    ]);

    $total = RecoveryReconciler::BATCH * 2;
    for ($index = 0; $index < $total; $index++) {
        FinalizeFixtures::running(ExecutionFixtures::listingWork());
    }
    $ordered = DB::table('prc_process_work')->where('state', 'running')->orderBy('id')->pluck('id');
    expect($ordered)->toHaveCount($total);
    foreach ($ordered as $index => $id) {
        FinalizeFixtures::expire(new WorkId(new ProcessUuid((string) $id)), 3_600 - $index);
    }
    $expired = DB::table('prc_process_work')
        ->where('state', 'running')
        ->orderBy('lease_until')
        ->orderBy('id')
        ->pluck('id')
        ->all();
    $oldestId = (string) $expired[0];
    $newestId = (string) $expired[array_key_last($expired)];

    $resolver = new class($oldestId) implements ProviderRecoveryResolver
    {
        public int $oldestInspections = 0;

        private bool $thrown = false;

        public function __construct(private readonly string $oldestId) {}

        public function inspect(WorkId $workId, ?ProviderRecoveryPlan $pinned): ?ProviderRecoveryPlan
        {
            if ($workId->value->value !== $this->oldestId) {
                return null;
            }
            $this->oldestInspections++;
            if ($this->thrown) {
                return null;
            }
            $this->thrown = true;

            throw new DependencyUnavailable('storage');
        }

        public function settleLocked(ProviderRecoveryPlan $plan): void
        {
            throw new LogicException('No provider operation exists to settle.');
        }

        public function revokeUnclaimedLocked(WorkId $workId): void {}
    };
    $reconciler = app(RecoveryReconciler::class);
    $reconciler->bindProviderResolver($resolver);

    expect($reconciler->recoverExpiredRunning())->toBe(RecoveryReconciler::BATCH - 1)
        ->and(DB::table('prc_process_work')->where('state', 'running')->count())->toBe(RecoveryReconciler::BATCH + 1)
        ->and(DB::table('prc_process_work')->where('id', $oldestId)->value('state'))->toBe('running')
        ->and(DB::table('prc_process_work')->where('id', $newestId)->value('state'))->toBe('running')
        ->and($resolver->oldestInspections)->toBe(1)
        ->and($reconciler->recoverExpiredRunning())->toBe(RecoveryReconciler::BATCH + 1)
        ->and(DB::table('prc_process_work')->where('id', $oldestId)->value('state'))->toBe('ready')
        ->and(DB::table('prc_process_work')->where('id', $newestId)->value('state'))->toBe('ready')
        ->and($resolver->oldestInspections)->toBe(2)
        ->and(DB::table('prc_process_work')->where('state', 'running')->count())->toBe(0);
});
