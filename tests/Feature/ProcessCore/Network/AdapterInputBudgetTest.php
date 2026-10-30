<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Exceptions\Procurement\Crawler\CrawlerInputRecoveryRequired;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Procurement;
use App\Models\User;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Crawler\StartCrawlerRunAction;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\PlatformAdapterRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\Http\CallerFixtures as C;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

test('input retry budget пауза и release lease атомарны; автоматический старт не снимает hold', function (): void {
    $wire = new WireFixture;
    try {
        $registry = C::platform($wire);
        $crawler = Crawler::factory()->for($registry)->create();
        $runs = app(CrawlerRunManager::class);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $run = $runs->startOrResume($crawler, RunPurpose::Profile, null);
            expect($runs->acquireExecutionLease($run))->toBeTrue();
            $runs->recordInputFailure($run, hash('sha256', 'same_input'), 3);
            expect($run->status)->toBe(RunStatus::Paused)->and($run->attempts)->toBe($attempt)
                ->and($run->execution_owner_token)->toBeNull()->and($run->execution_lease_expires_at)->toBeNull()
                ->and($run->failed_permanently)->toBe($attempt === 3);
            $again = $runs->startOrResume($crawler, RunPurpose::Profile, null);
            expect($again->status)->toBe(RunStatus::Paused)->and($runs->acquireExecutionLease($again))->toBeFalse();
            if ($attempt < 3) {
                DB::table('prc_crawler_runs')->where('id', $run->id)->update(['next_retry_at' => now()->subSecond()]);
            }
        }
        expect($wire->receipts())->toBe([]);
        Queue::fake();
        $starter = app(StartCrawlerRunAction::class);
        expect(fn () => $starter->resume($crawler))->toThrow(CrawlerInputRecoveryRequired::class);
        $actor = User::factory()->create();
        $starter->resume($crawler, actorId: $actor->id, reason: 'Проверены исходные данные');
        Queue::assertPushed(CrawlRunJob::class);
        $again = $runs->startOrResume($crawler, RunPurpose::Profile, null);
        expect($again->status)->toBe(RunStatus::Running)->and($again->attempts)->toBe(0)
            ->and($again->failed_permanently)->toBeFalse()->and($runs->acquireExecutionLease($again))->toBeTrue();
    } finally {
        $wire->stop();
    }
})->group('t05', 'p26');

test('partial projection metrics не стирают локальную историю layout failures карточки', function (): void {
    $wire = new WireFixture;
    try {
        $registry = C::platform($wire);
        $procurement = Procurement::factory()->for($registry)->create();
        $contract = app(PlatformAdapterRegistry::class)->adapterContract('eis', 'detail');
        $store = app(ProcurementStoreRepository::class);
        $store->recordDetailContractFailure($procurement, $contract, 'detail.layout_drift', 3);
        $store->markDetailFailed($procurement, 'Required detail snapshots are incomplete.', now()->addMinute(), false,
            ['documents' => ['snapshot_status' => 'incomplete']]);
        expect($procurement->detail_parse_outcome['input_validation']['failures'])->toBe(1)
            ->and($procurement->detail_parse_outcome['documents']['snapshot_status'])->toBe('incomplete');
        $store->recordDetailContractFailure($procurement, $contract, 'detail.layout_drift', 3);
        expect($procurement->detail_parse_outcome['input_validation']['failures'])->toBe(2)
            ->and($procurement->detail_attempts)->toBe(1)->and($procurement->detail_failed)->toBeFalse();
    } finally {
        $wire->stop();
    }
})->group('t05', 'p26');
