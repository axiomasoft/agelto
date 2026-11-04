<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\WorkFeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\DocumentFeederFixtures;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('document-processing', 'admission-fairness', 'sustained-workload');
beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
});
afterEach(function (): void {
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
});

test('два feeder W10 W20 ограничивают reservations трёх cohorts и running C без Redis authority', function (int $width): void {
    $limits = ['max_outstanding' => $width, 'max_running' => 10, 'low_watermark' => intdiv($width, 2), 'refill_max' => intdiv($width, 2)];
    FinalizeFixtures::authority([StageCode::Screening], ['analysis' => $limits, 'analysis.screening' => $limits]);
    foreach (['manual', 'platform:aaa', 'platform:bbb'] as $cohort) {
        for ($i = 0; $i < $width; $i++) {
            DocumentFeederFixtures::analysis($cohort);
        }
    }
    $workers = [];
    foreach (['one', 'two'] as $name) {
        $workers[$name] = sprintf('config(["process.lanes" => %s]);
            \Tests\Support\ProcessCore\DocumentFeederFixtures::bindAnalysis();
            $sum=0; for ($i=0;$i<4;$i++) { $sum+=app(\App\Services\Procurement\Process\WorkFeeder::class)->tick("analysis"); }
            return ["granted"=>$sum,"pid"=>\Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS pid")->pid];',
            var_export(config('process.lanes'), true));
    }
    // Each process reconstructs policy from its testing config and uses independent PG connections.
    $result = ConcurrentTransactions::concurrently($workers);
    expect($result['one']['pid'])->not->toBe($result['two']['pid'])
        ->and($result['one']['granted'] + $result['two']['granted'])->toBe($width)
        ->and(ExecutionFixtures::laneCounts('analysis')['outstanding'])->toBe($width)
        ->and(ExecutionFixtures::laneCounts('analysis.screening')['outstanding'])->toBe($width)
        ->and(DB::table('prc_admission_groups')->where('state', 'admitted')->count())->toBe($width);
    $cohorts = DB::table('prc_process_work')->where('state', 'admitted')->selectRaw('cohort_key,count(*) AS total')->groupBy('cohort_key')->pluck('total', 'cohort_key');
    expect($cohorts)->toHaveCount(3)->and(max($cohorts->all()))->toBeLessThanOrEqual((int) ceil($width / 2));
    $claimed = 0;
    foreach (DB::table('prc_process_work')->where('state', 'admitted')->get() as $row) {
        $id = new WorkId(new ProcessUuid($row->id));
        $claimed += (int) (app(WorkStoreRepository::class)->claim($id, ExecutionFixtures::delivery($id))->lease !== null);
    }
    expect($claimed)->toBe(10)->and(ExecutionFixtures::laneCounts('analysis')['running'])->toBe(10)
        ->and(ExecutionFixtures::laneCounts('analysis.screening')['running'])->toBe(10)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBeGreaterThanOrEqual($width)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
})->with([10, 20]);

test('age900 сохраняет старый analysis перед новым ingress внутри выбранного cohort', function (): void {
    FinalizeFixtures::authority([StageCode::Screening], ['analysis' => ['refill_max' => 1]]);
    $old = DocumentFeederFixtures::analysis('manual');
    ExecutionFixtures::age($old, 1000);
    $new = DocumentFeederFixtures::analysis('manual');
    DB::table('prc_process_work')->where('id', $old->value->value)->update(['priority' => 1]);
    DB::table('prc_process_work')->where('id', $new->value->value)->update(['priority' => 999]);
    expect(app(WorkFeeder::class)->tick('analysis'))->toBe(1)
        ->and(ExecutionFixtures::work($old)->state)->toBe('admitted')
        ->and(ExecutionFixtures::work($new)->state)->toBe('ready');
});

test('два document producers атомарно activate один intent и создают один missing prepare', function (): void {
    $doc = DocumentStorageFixtures::document();
    $revision = DocumentStorageFixtures::observe(new DocumentHead($doc->id, 1, null),
        DocumentStorageFixtures::upload('immutable source', DocumentStorageFixtures::domain()));
    $intent = LedgerFixtures::uuid();
    DB::transaction(fn () => app(WorkStoreRepository::class)->storeIntent($intent,
        LedgerFixtures::hash('pending'), StageCode::Download,
        new SubjectKey(SubjectKind::Document, $doc->id),
        LedgerFixtures::hash('semantic'), LedgerFixtures::hash('access'),
        1, $revision->head->generation, IntentState::Pending));
    $body = 'return ["created" => app(\\App\\Services\\Procurement\\Document\\DocumentFeeder::class)->reconcile(),
        "pid" => \\Illuminate\\Support\\Facades\\DB::selectOne("SELECT pg_backend_pid() AS pid")->pid];';
    $results = ConcurrentTransactions::concurrently(['one' => $body, 'two' => $body]);
    expect($results['one']['pid'])->not->toBe($results['two']['pid'])
        ->and($results['one']['created'] + $results['two']['created'])->toBe(2)
        ->and(DB::table('prc_process_work')->where('stage', 'download')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->where('stage', 'prepare')->count())->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('id', $intent->value)->value('state'))->toBe('active')
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});
