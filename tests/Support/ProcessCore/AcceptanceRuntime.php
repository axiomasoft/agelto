<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Process\StageCode;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Proxyma\CallerOutbound as OutboundExecutor;
use App\Support\Telemetry\TelemetrySanitizer;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\SupervisorCommandString;
use Laravel\Horizon\WorkerCommandString;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\TestEnvironmentGuard;

/** Test-only composition: real Horizon/ledger/permit/HTTP, deterministic analysis content. */
final readonly class AcceptanceRuntime implements StageHandler
{
    /** @param array{root: string, url: string, target: string, route: string, width: int, slices: int} $spec */
    public function __construct(private array $spec) {}

    public static function boot(string $path): void
    {
        TestEnvironmentGuard::assertBootstrapTestDatabase(app());
        $root = realpath(dirname($path));
        if (getenv('PROCESS_CORE_TEST') !== '1' || ! app()->environment('testing')
            || $root === false || ! str_starts_with($root, '/var/www/html/storage/app/testing/')
            || basename($path) !== 'acceptance.json' || is_link($path)) {
            throw new \LogicException('Invalid isolated acceptance runtime.');
        }
        $spec = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($spec) || ($spec['root'] ?? null) !== $root
            || ! in_array($spec['width'] ?? null, [10, 20], true)
            || ! is_int($spec['slices'] ?? null) || $spec['slices'] < 2 || $spec['slices'] > 8) {
            throw new \LogicException('Invalid acceptance profile.');
        }
        $lanes = config('process.lanes');
        foreach (['analysis', 'analysis.screening'] as $lane) {
            $lanes[$lane] = array_replace($lanes[$lane], [
                'max_outstanding' => $spec['width'], 'max_running' => 10,
                'low_watermark' => intdiv($spec['width'], 2), 'refill_max' => intdiv($spec['width'], 2),
            ]);
        }
        config(['process.lanes' => $lanes]);
        self::shortPolicy();
        FinalizeFixtures::bind(StageCode::Screening);
        app(StageRegistry::class)->bindHandler(StageCode::Screening, new self($spec));
        // Every Horizon child must bootstrap this same explicit test composition.
        SupervisorCommandString::$command = 'exec @php tests/Support/ProcessCore/artisan.php horizon:supervisor';
        WorkerCommandString::$command = 'exec @php tests/Support/ProcessCore/artisan.php horizon:work';
        $supervisors = array_fill_keys(array_keys(config('horizon.defaults')), ['maxProcesses' => 0]);
        $supervisors['supervisor-process-ai'] = ['connection' => 'process_ai', 'queue' => ['process-ai'],
            'balance' => 'simple', 'maxProcesses' => 10, 'minProcesses' => 10, 'tries' => 1,
            'timeout' => 40, 'memory' => 256, 'sleep' => 1, 'maxTime' => 900];
        config(['horizon.environments.testing' => $supervisors]);
        Queue::before(static function ($event) use ($spec): void {
            $payload = $event->job->payload();
            $job = unserialize($payload['data']['command'] ?? '', ['allowed_classes' => [ExecuteWorkJob::class]]);
            if ($job instanceof ExecuteWorkJob) {
                self::barrier($spec['root'], 'before-claim', $job->workId);
            }
        });
        Event::listen(JobQueued::class, static function (JobQueued $event) use ($spec): void {
            if ($event->job instanceof ExecuteWorkJob) {
                self::barrier($spec['root'], 'publish-before-mark', $event->job->workId);
            }
        });
        DB::listen(static function ($event) use ($spec): void {
            if (str_contains($event->sql, 'UPDATE prc_process_outbox o') && ($event->bindings[0] ?? null) === 'work_wake') {
                DB::afterCommit(static function () use ($event, $spec): void {
                    $payload = DB::table('prc_process_outbox')->where('claim_token', $event->bindings[2])->value('payload');
                    if (is_string($payload)) {
                        $wake = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
                        self::barrier($spec['root'], 'relay-claim-before-publish', $wake['work_id']);
                    }
                });
            }
            if (str_contains(strtolower($event->sql), 'insert into') && str_contains($event->sql, 'pxm_attempts')) {
                $physicalAttemptId = $event->bindings[3] ?? null;
                if (! is_string($physicalAttemptId)) {
                    return;
                }
                DB::afterCommit(static function () use ($physicalAttemptId, $spec): void {
                    $work = DB::table('pxm_attempts as attempt')
                        ->join('pxm_operations as operation', 'operation.id', '=', 'attempt.operation_id')
                        ->join('prc_process_attempts as process_attempt', static function ($join): void {
                            $join->on(DB::raw('process_attempt.id::text'), '=', 'operation.owner_id');
                        })
                        ->where('attempt.physical_attempt_id', $physicalAttemptId)
                        ->where('operation.owner_type', 'process_attempt')
                        ->value('process_attempt.work_id');
                    self::barrier($spec['root'], 'sent-intent-before-wire', is_string($work) ? $work : null);
                });
            }
            if (str_contains($event->sql, 'INSERT INTO prc_process_transitions') && ($event->bindings[5] ?? null) === 'succeeded') {
                self::barrier($spec['root'], 'finalize-before-commit', $event->bindings[2]);
                DB::afterCommit(static fn () => self::barrier($spec['root'], 'commit-before-ack', $event->bindings[2]));
            }
            if (str_contains($event->sql, 'INSERT INTO prc_process_transitions') && ($event->bindings[4] ?? null) === 'running'
                && ($event->bindings[5] ?? null) === 'ready') {
                DB::afterCommit(static fn () => self::barrier($spec['root'], 'mid-range-checkpoint', $event->bindings[2]));
            }
        });
    }

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        try {
            return $this->run($input, $execution);
        } catch (\Throwable $failure) {
            file_put_contents($this->spec['root'].'/faults.jsonl', json_encode([
                'class' => $failure::class, 'message' => app(TelemetrySanitizer::class)->exceptionMessage($failure),
                'file' => $failure->getFile(), 'line' => $failure->getLine(),
            ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            throw $failure;
        }
    }

    private function run(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        $work = $execution->lease()->workId->value->value;
        ProxymaCallerFixtures::configure();
        $lease = $execution->lease();
        $position = $lease->checkpoint?->stageProgress;
        $position = $position instanceof FixtureStageCheckpoint ? $position->position : 0;
        self::barrier($this->spec['root'], 'after-lease', $work);
        $url = new SafeUrl($this->spec['url']);
        $route = new ProcessUuid($this->spec['route']);
        $platform = DB::table('prc_process_work')->where('id', $work)->soleValue('platform_id');
        $target = DB::table('prc_source_targets')->where('platform_id', $platform)->where('target_key', 'card')->soleValue('id');
        $plan = new OutboundRequestPlan(LedgerFixtures::uuid(), new ProcessUuid($target), 'GET', $url,
            TransportFixtures::profile(), TrafficClass::Detail, IdempotencyClass::ReadOnly);
        $gate = ProxymaCallerFixtures::gate($route->value);
        self::barrier($this->spec['root'], 'before-send', $work);
        $outcome = app(OutboundExecutor::class)->execute($plan, $execution, $gate,
            new UrlPolicy([$url->origin], ['fixture.test' => ['127.0.0.1']]), new TransferBudget(65536, 65536));
        if ($outcome instanceof TransportDeferred) {
            return app(FetchOutcomeBridge::class)->failure(new FetchDeferred($outcome->reason, $outcome->retryAt));
        }
        if (! $outcome instanceof TransportSucceeded || $outcome->response->status !== 200) {
            throw new \RuntimeException('Acceptance local HTTP did not succeed: '.get_debug_type($outcome).' '.($outcome->reason->value ?? $outcome->response->status ?? 'unknown')
                .' '.json_encode(DB::table('pxm_attempts')->get(['physical_attempt_id', 'status']), JSON_THROW_ON_ERROR)
                .' '.json_encode(DB::table('prc_observations')->get(['reason_code']), JSON_THROW_ON_ERROR));
        }
        self::barrier($this->spec['root'], 'after-bytes', $work);
        file_put_contents($this->spec['root'].'/executions.jsonl', json_encode([
            'work' => $work, 'generation' => $lease->generation, 'position' => $position,
            'pid' => getmypid(), 'rss_kib' => self::rss(), 'at_ns' => hrtime(true),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

        return $position + 1 < $this->spec['slices']
            ? new StagePartial(new FixtureStageCheckpoint($position + 1, StageCode::Screening), new MissingParts(['next_slice']))
            : FinalizeFixtures::succeeded(StageCode::Screening, 'acceptance/'.$work);
    }

    public static function barrier(string $root, string $boundary, ?string $work): void
    {
        $arm = $root.'/arm-'.$boundary;
        if (! is_file($arm) || ! @rename($arm, $arm.'.claimed')) {
            return;
        }
        file_put_contents($root.'/boundary.json', json_encode(['boundary' => $boundary, 'work' => $work,
            'pid' => getmypid(), 'backend_pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR));
        $until = hrtime(true) + 15_000_000_000;
        while (! is_file($root.'/release') && hrtime(true) < $until) {
            usleep(10_000);
        }
        if (! is_file($root.'/release')) {
            throw new \RuntimeException('Acceptance barrier was not killed or released.');
        }
    }

    public static function rss(): int
    {
        preg_match('/^VmRSS:\s+(\d+)/m', (string) file_get_contents('/proc/self/status'), $match);

        return (int) ($match[1] ?? 0);
    }

    public static function shortPolicy(): void
    {
        if (getenv('PROCESS_CORE_TEST') !== '1' || ! app()->environment('testing')) {
            throw new \LogicException('Acceptance timing policy is isolated-test-only.');
        }
        config(['process.execution' => ['heartbeat_seconds' => 2, 'finalization_reserve_seconds' => 15, 'lease_safety_seconds' => 2],
            'process.daemons.relay.claim_seconds' => 3,
            'process.stages.screening' => ['claim_visibility' => 42, 'slice' => 36, 'lease' => 44],
            'process.stages.extraction' => ['claim_visibility' => 42, 'slice' => 36, 'lease' => 44],
            'process.queues.screening.job_timeout' => 38, 'process.queues.extraction.job_timeout' => 38,
            'horizon.defaults.supervisor-process-ai.timeout' => 40, 'queue.connections.process_ai.retry_after' => 42]);
    }
}
