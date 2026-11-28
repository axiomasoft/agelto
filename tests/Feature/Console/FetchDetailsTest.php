<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Outcomes\StageFailedItem;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Governance\OperatorCommandStatus;
use App\Enums\Procurement\Process\Outcomes\ItemFailureReason;
use App\Models\Procurement\Platform\Registry;
use App\Models\User;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Services\Procurement\Process\Diagnostics\ProcessOperatorCommands;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\RefreshFixtures;

beforeEach(function (): void {
    DetailFixtures::bind();
    $this->artisan('guard:sync-roles')->assertSuccessful();
    Queue::fake();
});

test('manual detail retry targets exact Process work and preserves the exhausted source history', function (): void {
    $work = E::detailWork();
    $failure = new StageFailedItem(ItemFailureReason::ContractRejected, new ArtifactRef('fixture', 'rejected', LedgerFixtures::hash('rejected')));
    for ($i = 0; $i < 3; $i++) {
        app(WorkFinalizer::class)->finish($i === 0 ? FinalizeFixtures::running($work) : FinalizeFixtures::rerun($work), $failure);
    }
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $version = E::work($work)->transition_seq;
    $command = LedgerFixtures::uuid();
    $commands = app(ProcessOperatorCommands::class);
    $retry = $commands->retryFailedItem($operator, $work, $version, $command, 'Checked source input');
    expect($retry->status)->toBe(OperatorCommandStatus::Applied)
        ->and($retry->successor)->not->toBeNull()
        ->and($commands->retryFailedItem($operator, $work, $version, $command, 'Checked source input')->status)->toBe(OperatorCommandStatus::Duplicate)
        ->and(E::work($work)->state)->toBe('failed_item')->and(E::work($work)->item_failures)->toBe(3)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(1)
        ->and(array_key_exists('crawler:fetch-details', Artisan::all()))->toBeFalse();
    Queue::assertNothingPushed();
});

test('an explicit missing Process work returns not-found without allocation', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $result = app(ProcessOperatorCommands::class)->retryFailedItem($operator,
        new WorkId(LedgerFixtures::uuid()), 1, LedgerFixtures::uuid(), 'Missing source input');
    expect($result->status)->toBe(OperatorCommandStatus::NotFound)
        ->and(DB::table('prc_process_work')->count())->toBe(0)
        ->and(DB::table('prc_operation_intents')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('periodic detail producer allocates only its bounded due batch and leaves fresh templates untouched', function (): void {
    $registry = Registry::factory()->eis()->create();
    $fixtures = [];
    foreach (range(1, 4) as $n) {
        $fixtures[] = RefreshFixtures::dueDetail('manual-batch-'.$n, $registry);
    }
    DB::update("UPDATE prc_operation_intents SET next_due_at=clock_timestamp()+interval '1 day' WHERE id=?", [$fixtures[3]['input']->intentId->value]);
    $allocated = app(RefreshRepository::class)->due(2);
    expect($allocated)->toHaveCount(2)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->where('stage', 'detail')->count())->toBe(2)
        ->and($fixtures[0]['p']->refresh()->detail_cycle_seq)->toBe(1)
        ->and($fixtures[1]['p']->refresh()->detail_cycle_seq)->toBe(1)
        ->and($fixtures[2]['p']->refresh()->detail_cycle_seq)->toBe(0)
        ->and($fixtures[3]['p']->refresh()->detail_cycle_seq)->toBe(0);
    Queue::assertNothingPushed();
});
