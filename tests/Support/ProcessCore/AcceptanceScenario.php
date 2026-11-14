<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\DaemonPolicy;
use App\Services\Procurement\Process\FeederDaemon;
use App\Services\Procurement\Process\ProjectorDaemon;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\RelayDaemon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Domain\Policy\TenantKey;
use Symfony\Component\Process\Process;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;

final class AcceptanceScenario
{
    public readonly string $root;

    public readonly WireFixture $wire;

    private ?Process $horizon = null;

    private FeederDaemon $feeder;

    public function __construct(public readonly int $width = 10)
    {
        PermitFixtures::clean();
        ExecutionFixtures::truncateLedger();
        DocumentStorageFixtures::reset();
        Redis::connection()->flushdb();
        $this->root = storage_path('app/testing/acceptance-'.bin2hex(random_bytes(6)));
        mkdir($this->root, 0700, true);
        $this->wire = new WireFixture;
        $this->wire->respond('/ok', 'anchored fixture representation', headers: ['Content-Type' => 'text/plain']);
        $permits = new PermitFixtures($this->wire);
        foreach (['aaa', 'bbb'] as $code) {
            $this->preparePlatformCatalog($code);
        }
        HistoricalNetworkFixtures::synchronize();
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        // Same actual GCRA+FIFO algorithm, measured test rate; no production throughput claim.
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 20_000, 'max_inflight' => 2]);
        ProxymaCallerFixtures::installAcceptanceProfile($this->wire);
        file_put_contents($this->root.'/acceptance.json', json_encode([
            'root' => $this->root, 'url' => $this->wire->url('/ok')->reveal(),
            'target' => $permits->target->value, 'route' => $permits->route->routeId->value,
            'width' => $width, 'slices' => 3,
        ], JSON_THROW_ON_ERROR));
        $limits = ['max_outstanding' => $width, 'max_running' => 10,
            'low_watermark' => intdiv($width, 2), 'refill_max' => intdiv($width, 2)];
        ExecutionFixtures::authority([StageCode::Screening], ['analysis' => $limits, 'analysis.screening' => $limits]);
        AcceptanceRuntime::boot($this->root.'/acceptance.json');
        // This corpus already creates its immutable subjects and work. Consume feeder wakes
        // with the real daemon without registering unrelated document/acquisition producers.
        $this->feeder = app()->build(FeederDaemon::class);
    }

    /** @return list<string> */
    public function documents(int $count, bool $old = false): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $work = DocumentFeederFixtures::analysis('platform:'.($i % 2 === 0 ? 'aaa' : 'bbb'));
            if ($old) {
                ExecutionFixtures::age($work, 1000);
                DB::table('prc_process_work')->where('id', $work->value->value)->update(['priority' => 1]);
            }
            $ids[] = $work->value->value;
        }

        return $ids;
    }

    private function preparePlatformCatalog(string $code): void
    {
        $platform = Platform::query()->firstOrCreate(['code' => $code], [
            'name' => $code,
            'base_url' => "https://{$code}.test",
            'is_active' => false,
            'is_public' => true,
        ]);
        Registry::query()->firstOrCreate(['platform_id' => $platform->id, 'code' => 'fixture'], [
            'name' => 'Fixture',
            'settings' => [],
        ]);
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($code, [
            new SourceTargetDefinition('card', 'detail', $this->wire->url('/ok')->origin,
                [TransportFixtures::profile()], new OriginBudgetPolicy(600, 1, 2)),
        ]));
    }

    public function start(): void
    {
        $this->horizon = new Process([PHP_BINARY, 'tests/Support/ProcessCore/artisan.php', 'horizon'], base_path(), [
            'PROCESS_CORE_ACCEPTANCE_FILE' => $this->root.'/acceptance.json',
            'PROCESS_CORE_QUEUE_MODE' => 'redis', 'QUEUE_CONNECTION' => 'redis',
        ]);
        $this->horizon->setTimeout(null)->start();
    }

    /** @return array<string, array<string, mixed>> */
    public function twoFeeders(): array
    {
        $path = var_export($this->root.'/acceptance.json', true);
        $code = '\\Tests\\Support\\ProcessCore\\AcceptanceRuntime::boot('.$path.');'
            .'return ["pid" => getmypid(), "grants" => app(\\App\\Services\\Procurement\\Process\\WorkFeeder::class)->tick("analysis")];';

        return ConcurrentTransactions::concurrently(['a' => $code, 'b' => $code]);
    }

    public function tick(): void
    {
        if ($this->horizon === null || ! $this->horizon->isRunning()) {
            throw new \RuntimeException('Acceptance Horizon stopped: '.$this->output());
        }
        $this->feeder->tick();
        $relay = app(RelayDaemon::class)->tick();
        $projection = app(ProjectorDaemon::class)->tick();
        if ($relay->errorCode !== null || $projection->errorCode !== null) {
            throw new \RuntimeException('Acceptance daemon degraded: '.$relay->errorCode.' '.$projection->errorCode);
        }
    }

    public function drain(float $seconds = 90, ?DaemonPolicy $reconcilePolicy = null): void
    {
        $until = hrtime(true) + (int) ($seconds * 1_000_000_000);
        $nextReconcileAt = 0;
        do {
            if ($reconcilePolicy === null) {
                $this->recover();
            }
            if ($reconcilePolicy !== null && hrtime(true) >= $nextReconcileAt) {
                $this->recover();
                $nextReconcileAt = hrtime(true) + DaemonPolicy::jittered(
                    $reconcilePolicy->reconcileTickSeconds,
                    $reconcilePolicy->reconcileJitterPercent,
                ) * 1_000_000;
            }
            $this->tick();
            if (is_file($this->root.'/faults.jsonl')) {
                throw new \RuntimeException((string) file_get_contents($this->root.'/faults.jsonl'));
            }
            $this->assertBounds();
            if (DB::table('prc_process_work')->where('state', '!=', 'succeeded')->count() === 0
                && DB::table('prc_process_outbox')->whereNull('published_at')->count() === 0) {
                return;
            }
            usleep(100_000);
        } while (hrtime(true) < $until);
        throw new \RuntimeException('Horizon drain timed out: '.json_encode(DB::table('prc_process_work')->selectRaw('state,reason_code,count(*)')->groupBy('state', 'reason_code')->get()).$this->output());
    }

    public function assertBounds(): void
    {
        $counts = ExecutionFixtures::laneCounts('analysis');
        if ($counts['outstanding'] > $this->width || $counts['running'] > 10) {
            throw new \RuntimeException('Analysis W/C oversubscription.');
        }
    }

    /** @return array<string, mixed> */
    public function awaitBoundary(string $boundary): array
    {
        $until = hrtime(true) + 15_000_000_000;
        do {
            $this->tick();
            if (is_file($this->root.'/boundary.json')) {
                $marker = json_decode((string) file_get_contents($this->root.'/boundary.json'), true, flags: JSON_THROW_ON_ERROR);
                if ($marker['boundary'] === $boundary) {
                    return $marker;
                }
            }
            usleep(20_000);
        } while (hrtime(true) < $until);
        throw new \RuntimeException('Named boundary not reached: '.$boundary.$this->output());
    }

    public function recover(): void
    {
        app(RecoveryReconciler::class)->recoverExpiredRunning();
        app(RecoveryReconciler::class)->recoverAdmitted();
        app(Reconciler::class)->run(TenantKey::system());
    }

    public function output(): string
    {
        return $this->horizon === null ? '' : $this->horizon->getOutput().$this->horizon->getErrorOutput();
    }

    public function stop(): void
    {
        if ($this->horizon !== null && $this->horizon->isRunning()) {
            $this->horizon->signal(SIGTERM);
            $until = hrtime(true) + 10_000_000_000;
            while ($this->horizon->isRunning() && hrtime(true) < $until) {
                usleep(20_000);
            }
            $this->horizon->stop(0, SIGKILL);
        }
    }

    public function close(): void
    {
        $this->stop();
        $this->wire->stop();
        File::deleteDirectory($this->root);
        Redis::connection()->flushdb();
        ExecutionFixtures::truncateLedger();
        DocumentStorageFixtures::reset();
    }
}
