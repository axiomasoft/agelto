<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\ExtractionProgress;
use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\StagePartial;
use App\Services\Procurement\Extraction\AnalysisProviderConnections;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AcceptanceRuntime;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\StructuredChainFixtures;

pest()->group('provider-and-worker-recovery', 'analysis-execution', 'faults');

test('SIGKILL настоящего extraction runner различает authorization без claim и ответ до durable raw', function (string $boundary): void {
    $server = new WireFixture;
    $root = storage_path('app/testing/paid-kill-'.bin2hex(random_bytes(6)));
    mkdir($root, 0700, true);
    try {
        PermitFixtures::clean();
        AcceptanceRuntime::shortPolicy();
        $fixture = new StructuredChainFixtures($server);
        $work = $fixture->work;
        app(WorkFinalizer::class)->finish($fixture->lease, new StagePartial(new ExtractionProgress, new MissingParts(['start_real_runner'])));
        app(WorkFeeder::class)->tick('analysis');
        $delivery = new DeliveryEnvelope($work, ExecutionFixtures::delivery($work), LedgerFixtures::uuid(), 1);
        $connection = app(AnalysisProviderConnections::class)->for($fixture->window->run->recipe->llm);
        $payload = base64_encode(serialize([$connection, $delivery, Storage::disk('local')->path(''), $boundary, $root]));
        $code = str_replace('__PAYLOAD__', var_export($payload, true), <<<'PHP'
            [$connection,$delivery,$localRoot,$boundary,$root] = unserialize(base64_decode(__PAYLOAD__));
            \Tests\Support\ProcessCore\AcceptanceRuntime::shortPolicy();
            \Tests\Support\ProcessCore\ExtractionRuntimeFixtures::bind();
            \Tests\Support\ProcessCore\ProxymaProviderFixtures::configure();
            config(['filesystems.disks.local.root'=>$localRoot]);
            \Illuminate\Support\Facades\Storage::forgetDisk('local');
            app(\App\Services\Llm\Execution\ReceiptPolicies::class)->register('fixture-provider',new \App\Services\Llm\Providers\GeminiAssetReceiptPolicy);
            app(\App\Services\Procurement\Extraction\AnalysisProviderConnections::class)->register($connection);
            \Illuminate\Support\Facades\DB::listen(static function($event) use ($boundary,$root,$delivery): void {
                $authorization = $boundary === 'authorization-before-send-claim'
                    && str_contains($event->sql,"UPDATE llm_provider_requests SET state='sending'");
                // The direct RequestRepository::get call from StructuredCallExecutor occurs
                // after sendOnce returns, before RequestLedger::capture. The gate's earlier
                // reads have a different caller. Server count below independently proves wire.
                $response = $boundary === 'response-before-durable-raw' && array_any(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
                    static fn(array $frame): bool => ($frame['function']??null)==='get'
                        && ($frame['class']??null)===\App\Repositories\Llm\Request\RequestRepository::class
                        && str_ends_with($frame['file']??'', '/StructuredCallExecutor.php'));
                if ($authorization || $response) {
                    $kill = static function() use ($root,$boundary,$delivery): void {
                        file_put_contents($root.'/killed.json',json_encode(['work'=>$delivery->workId->value->value,
                            'pid'=>getmypid(),'boundary'=>$boundary],JSON_THROW_ON_ERROR));
                        posix_kill(getmypid(),SIGKILL);
                    };
                    if ($authorization) { \Illuminate\Support\Facades\DB::afterCommit($kill); } else { $kill(); }
                }
            });
            app(\App\Services\Procurement\Process\WorkRunner::class)->runDelivery($delivery);
            return ['unexpected_survival'=>true];
            PHP);
        $killed = false;
        try {
            ConcurrentTransactions::execute($code);
        } catch (RuntimeException) {
            $killed = true;
        }
        expect($killed)->toBeTrue()->and(is_file($root.'/killed.json'))->toBeTrue();
        $marker = json_decode((string) file_get_contents($root.'/killed.json'), true, flags: JSON_THROW_ON_ERROR);
        $notSent = $boundary === 'authorization-before-send-claim';
        expect($marker['work'])->toBe($work->value->value)
            ->and(ExecutionFixtures::work($work)->state)->toBe('running')
            ->and($server->receipts())->toHaveCount($notSent ? 0 : 1)
            ->and(DB::table('llm_provider_calls')->count())->toBe(1)
            ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe($notSent ? 0 : 1)
            ->and(DB::table('llm_provider_calls')->where('receipt_state', 'verified')->count())->toBe(0)
            ->and(DB::table('prc_analysis_chunks')->count())->toBe(0);
        $remainingLeaseSeconds = (float) DB::table('prc_process_work')->where('id', $work->value->value)
            ->selectRaw('extract(epoch from (lease_until - clock_timestamp())) as seconds')->value('seconds');
        $until = hrtime(true) + (int) ceil(max(0, $remainingLeaseSeconds + 2 + 3) * 1_000_000_000);
        while (ExecutionFixtures::work($work)->state === 'running' && hrtime(true) < $until) {
            app(RecoveryReconciler::class)->recoverExpiredRunning();
            usleep(100_000);
        }
        expect(ExecutionFixtures::work($work)->state)->toBe($notSent ? 'ready' : 'blocked');
        if ($notSent) {
            expect(DB::table('llm_provider_calls')->soleValue('outcome'))->toBe('not_sent')
                ->and(DB::table('llm_budget_reservations')->soleValue('settled_amount'))->toBe('0.00000000');
            $lease = FinalizeFixtures::running($work);
            app(WorkFinalizer::class)->finish($lease, $fixture->extract($lease));
            $fixture->publish();
            expect(DB::table('llm_provider_requests')->count())->toBe(1)
                ->and(DB::table('llm_provider_calls')->count())->toBe(2)
                ->and($server->receipts())->toHaveCount(1)
                ->and(DB::table('prc_estimates')->count())->toBe(1);
        } else {
            expect(ExecutionFixtures::work($work)->reason_code)->toBe('provider_result_unknown')
                ->and(DB::table('llm_provider_requests')->soleValue('state'))->toBe('unknown')
                ->and(DB::table('llm_budget_reservations')->soleValue('state'))->toBe('reserved')
                ->and(app(WorkFeeder::class)->tick('analysis'))->toBe(0)
                ->and(DB::table('prc_estimates')->count())->toBe(0);
        }
    } finally {
        $server->stop();
        File::deleteDirectory($root);
    }
})->with(['authorization-before-send-claim', 'response-before-durable-raw']);
