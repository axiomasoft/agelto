<?php

declare(strict_types=1);

use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Procurement\Process\RecoveryReconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;

pest()->group('p39', 't15', 't22');

test('restart и два настоящих reconciler процесса применяют один late receipt один раз', function (): void {
    $period = FinancialFixtures::reset();
    [$work, , , , $operation] = FinancialFixtures::authorized($period);
    FinancialFixtures::claim($operation);
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    app(ReceiptStore::class)->put(app(RequestRepository::class)->get($operation->physicalCallId), 200, FinancialFixtures::body());
    FinancialFixtures::due($work);
    $seq = ExecutionFixtures::work($work)->transition_seq;
    $root = Storage::disk('local')->path('');
    $code = 'config(["filesystems.disks.local.root" => '.var_export($root, true).']);'
        .'\\Illuminate\\Support\\Facades\\Storage::forgetDisk("local");'
        .'\\Tests\\Support\\ProcessCore\\FinalizeFixtures::bind(\\App\\Enums\\Procurement\\Process\\StageCode::Screening);'
        .'app(\\App\\Services\\Llm\\Execution\\ReceiptPolicies::class)->register("fixture-provider", new \\Tests\\Support\\ProcessCore\\FinancialFixtures);'
        .'$count = app(\\App\\Services\\Procurement\\Process\\RecoveryReconciler::class)->recoverBlockedProvider();'
        .'return ["count" => $count, "pid" => \\Illuminate\\Support\\Facades\\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    $results = ConcurrentTransactions::concurrently(['first' => $code, 'second' => $code]);
    expect($results['first']['pid'])->not->toBe($results['second']['pid'])
        ->and($results['first']['count'] + $results['second']['count'])->toBe(1)
        ->and(ExecutionFixtures::work($work)->state)->toBe('ready')
        ->and(ExecutionFixtures::work($work)->transition_seq)->toBe($seq + 1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->value('settled_amount'))->toBe('0.00200001')
        ->and(DB::table('llm_provider_calls')->count())->toBe(1);
});
