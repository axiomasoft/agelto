<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\Process\StageCode;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(function (): void {
    F::bind();
    Queue::fake();
});

test('Process stale listing result cannot publish after a newer owner acquires the same page', function (): void {
    $run = F::run();
    $old = FinalizeFixtures::running($run['work']);
    FinalizeFixtures::expire($run['work']);
    expect(FinalizeFixtures::revoke($run['work']))->toBeTrue();
    $current = FinalizeFixtures::running($run['work']);
    $result = new StageSucceeded(new ListingResult(app(ListingStoreRepository::class)->task($run['input']), F::page([F::summary('late')])));
    expect(app(WorkFinalizer::class)->finish($old, $result)->status->value)->toBe('lost_ownership')
        ->and(DB::table('prc_procurements')->count())->toBe(0)
        ->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0)
        ->and(E::work($run['work'])->owner_token)->toBe($current->token->value)
        ->and(E::work($run['work'])->generation)->toBe($current->generation);
});

test('Process duplicate queued delivery cannot disturb an active owner or emit a second wake', function (): void {
    $run = F::run();
    FinalizeFixtures::running($run['work']);
    $before = DB::table('prc_process_work as w')->where('id', $run['work']->value->value)->selectRaw('to_jsonb(w)::text AS snapshot')->value('snapshot');
    $generation = E::work($run['work'])->dispatch_generation;
    (new ExecuteWorkJob($run['work']->value->value, $generation, LedgerFixtures::uuid()->value, 1))->handle(app(WorkRunner::class));
    expect(DB::table('prc_process_work as w')->where('id', $run['work']->value->value)->selectRaw('to_jsonb(w)::text AS snapshot')->value('snapshot'))->toBe($before)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)->and(DB::table('prc_process_attempts')->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('Process expired-work recovery cannot shorten a newer absolute target cooldown', function (): void {
    PermitFixtures::clean();
    try {
        $network = new PermitFixtures;
        E::authority([StageCode::Listing]);
        $run = F::run();
        FinalizeFixtures::running($run['work']);
        FinalizeFixtures::expire($run['work']);
        $until = DB::selectOne("SELECT (clock_timestamp()+interval '10 minutes')::text AS at")->at;
        DB::table('prc_target_health')->where('target_id', $network->target->value)->update(['state' => 'open', 'open_until' => $until]);
        expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
            ->and(DB::table('prc_target_health')->where('target_id', $network->target->value)->value('open_until'))->toBe($until)
            ->and(E::work($run['work'])->state)->toBe('ready')->and(DB::table('prc_request_attempts')->count())->toBe(0);
    } finally {
        PermitFixtures::clean();
    }
});
