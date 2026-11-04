<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StagePartial;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Models\User;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

test('input retry budget пауза и release lease атомарны; автоматический старт не снимает hold', function (): void {
    DetailFixtures::bind();
    $f = DetailFixtures::work();
    $failure = new StageFailedItem(ItemFailureReason::ContractRejected,
        new ArtifactRef('fixture', 'same_input', LedgerFixtures::hash('same_input')));
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $lease = $attempt === 1 ? FinalizeFixtures::running($f['work']) : FinalizeFixtures::rerun($f['work']);
        app(WorkFinalizer::class)->finish($lease, $failure);
        $row = ExecutionFixtures::work($f['work']);
        expect($row->item_failures)->toBe($attempt)
            ->and($row->owner_token)->toBeNull()->and($row->lease_until)->toBeNull()
            ->and($row->state)->toBe($attempt === 3 ? 'failed_item' : 'deferred')
            ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0);
    }
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $analyst = User::factory()->create();
    $analyst->assignRole('analyst');
    $commands = app(ProcessOperatorCommands::class);
    $command = LedgerFixtures::uuid();
    expect(fn () => $commands->retryFailedItem($analyst, $f['work'], $row->transition_seq, $command, 'Проверены исходные данные'))
        ->toThrow(AuthorizationException::class);
    $result = $commands->retryFailedItem($operator, $f['work'], $row->transition_seq, $command, 'Проверены исходные данные');
    expect($result->status)->toBe(OperatorCommandStatus::Applied)->and($result->successor)->not->toBeNull()
        ->and(ExecutionFixtures::work($f['work'])->item_failures)->toBe(3)
        ->and(ExecutionFixtures::work($result->successor)->item_failures)->toBe(0)
        ->and(ExecutionFixtures::work($result->successor)->state)->toBe('ready');
})->group('network-admission-probes');

test('partial projection metrics не стирают локальную историю layout failures карточки', function (): void {
    DetailFixtures::bind();
    $f = DetailFixtures::work();
    $failure = new StageFailedItem(ItemFailureReason::ContractRejected,
        new ArtifactRef('fixture', 'detail.layout_drift', LedgerFixtures::hash('layout')));
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($f['work']), $failure);
    $lease = FinalizeFixtures::rerun($f['work']);
    app(WorkFinalizer::class)->finish($lease, new StagePartial(
        new DetailProgress(['attributes' => new ArtifactRef('fixture', 'attributes', LedgerFixtures::hash('attributes'))]),
        new MissingParts(['documents'])));
    expect(ExecutionFixtures::work($f['work'])->item_failures)->toBe(1)
        ->and(ExecutionFixtures::work($f['work'])->state)->toBe('ready');
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($f['work']), $failure);
    expect(ExecutionFixtures::work($f['work'])->item_failures)->toBe(2)
        ->and(ExecutionFixtures::work($f['work'])->state)->toBe('deferred')
        ->and(DB::table('prc_process_attempts')->where('work_id', $f['work']->value->value)->count())->toBe(3)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
})->group('network-admission-probes');
