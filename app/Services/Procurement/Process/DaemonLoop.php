<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\DaemonTick;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Repositories\Procurement\Process\DaemonHeartbeatRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use Closure;

/**
 * Цикл долгоживущего daemon: tick → heartbeat → пауза. Heartbeat каждые
 * heartbeat_seconds (DB clock в repository) и сразу при смене кода ошибки, в том числе во время
 * длинной паузы backoff. Неожиданное исключение tick не убивает loop и не выдаётся за успех:
 * sanitized лог, degraded код в heartbeat и экспоненциальный backoff 1→30 s. Loop завершается
 * только по stop-флагу (SIGTERM/SIGINT ставит command), max runtime или memory limit —
 * процесс перезапускает docker. Сбой самого heartbeat (DB недоступна) не ломает loop: liveness
 * честно устареет, readiness покажет Down. Сетевой I/O внутри DB transaction здесь невозможен:
 * loop сам транзакций не открывает.
 */
final class DaemonLoop
{
    public const string STOP_SIGNAL = 'signal';

    public const string STOP_MAX_RUNTIME = 'max_runtime';

    public const string STOP_MEMORY = 'memory_limit';

    /** Пауза режется на куски: stop-флаг и heartbeat проверяются, а не ждут конца backoff. */
    private const int SLEEP_CHUNK_MS = 250;

    /** @var Closure(): int */
    private Closure $memoryUsage;

    /** @param (Closure(): int)|null $memoryUsage байты; по умолчанию реальная память процесса */
    public function __construct(
        private readonly ProcessClock $clock,
        private readonly DaemonHeartbeatRepository $heartbeats,
        private readonly DaemonPolicy $policy,
        ?Closure $memoryUsage = null,
    ) {
        $this->memoryUsage = $memoryUsage ?? static fn (): int => memory_get_usage(true);
    }

    /**
     * @param  Closure(): bool  $shouldStop
     * @return string STOP_* причина выхода
     */
    public function run(ProcessDaemon $daemon, ProcessUuid $instance, Closure $shouldStop): string
    {
        $heartbeatNs = Duration::seconds($this->policy->heartbeatSeconds)->nanos();
        $started = $this->clock->monotonicNs();
        $lastBeat = $started;
        $reportedError = null;
        $failures = 0;
        try {
            $this->heartbeats->register($instance, $daemon->kind());
        } catch (\Throwable $failure) {
            ProcessLog::daemonError($daemon->kind()->value, 'heartbeat_register_failed', $failure);
        }

        try {
            while (true) {
                $stop = $this->stopReason($started, $shouldStop);
                if ($stop !== null) {
                    return $stop;
                }
                try {
                    $tick = $daemon->tick();
                    $failures = 0;
                    $delayMs = $daemon->delayMs($tick);
                } catch (\Throwable $failure) {
                    $failures++;
                    $tick = new DaemonTick(0, self::errorCode($failure));
                    ProcessLog::daemonError($daemon->kind()->value, (string) $tick->errorCode, $failure);
                    $delayMs = $this->policy->backoffMs($failures);
                }
                if ($tick->errorCode !== $reportedError || $this->clock->monotonicNs() - $lastBeat >= $heartbeatNs) {
                    $this->beat($daemon, $instance, $tick->errorCode);
                    $reportedError = $tick->errorCode;
                    $lastBeat = $this->clock->monotonicNs();
                }
                while ($delayMs > 0) {
                    if ($this->stopReason($started, $shouldStop) !== null) {
                        break;
                    }
                    $chunk = min($delayMs, self::SLEEP_CHUNK_MS);
                    $this->clock->sleep(new Duration($chunk));
                    $delayMs -= $chunk;
                    if ($this->clock->monotonicNs() - $lastBeat >= $heartbeatNs) {
                        $this->beat($daemon, $instance, $reportedError);
                        $lastBeat = $this->clock->monotonicNs();
                    }
                }
            }
        } finally {
            $this->retire($daemon, $instance);
        }
    }

    /** @param Closure(): bool $shouldStop */
    private function stopReason(int $started, Closure $shouldStop): ?string
    {
        if ($shouldStop()) {
            return self::STOP_SIGNAL;
        }
        if ($this->clock->monotonicNs() - $started >= Duration::seconds($this->policy->maxRuntimeSeconds)->nanos()) {
            return self::STOP_MAX_RUNTIME;
        }
        if (($this->memoryUsage)() >= $this->policy->memoryLimitMb * 1024 * 1024) {
            return self::STOP_MEMORY;
        }

        return null;
    }

    private function beat(ProcessDaemon $daemon, ProcessUuid $instance, ?string $errorCode): void
    {
        try {
            if (! $this->heartbeats->beat($instance, $errorCode)) {
                // Startup may have raced a DB outage, or retention may have removed a stale row.
                // Restore this live instance and retain the current processing error.
                $this->heartbeats->register($instance, $daemon->kind(), $errorCode);
            }
        } catch (\Throwable $failure) {
            ProcessLog::daemonError($daemon->kind()->value, 'heartbeat_failed', $failure);
        }
    }

    private function retire(ProcessDaemon $daemon, ProcessUuid $instance): void
    {
        try {
            $this->heartbeats->retire($instance);
        } catch (\Throwable $failure) {
            ProcessLog::daemonError($daemon->kind()->value, 'heartbeat_retire_failed', $failure);
        }
    }

    /** Transient DB — отдельный код; всё остальное — дефект tick, видимый как degraded. */
    private static function errorCode(\Throwable $failure): string
    {
        return DatabaseFailureClassifier::isTransient($failure)
            ? 'database_unavailable'
            : 'tick_failed';
    }
}
