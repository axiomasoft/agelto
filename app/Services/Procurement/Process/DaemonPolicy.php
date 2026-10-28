<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use Illuminate\Contracts\Config\Repository;

/**
 * Boot-validated policy process daemons из `process.daemons` (bp-daemons): intervals, batch и claim
 * bounds, backoff и liveness window. Закрытая схема ключей; неполная или противоречивая
 * конфигурация отвергается с ключом config, а не заменяется default.
 */
final readonly class DaemonPolicy
{
    public int $heartbeatSeconds;

    public int $liveSeconds;

    public int $maxRuntimeSeconds;

    public int $memoryLimitMb;

    public int $errorBackoffMinSeconds;

    public int $errorBackoffMaxSeconds;

    public int $relayBatch;

    public int $relayClaimSeconds;

    public int $relayIdleMinMs;

    public int $relayIdleMaxMs;

    public int $reconcileTickSeconds;

    public int $reconcileJitterPercent;

    public int $feedTickSeconds;

    public int $feedJitterPercent;

    public int $feedPollMs;

    public int $feedWakeBatch;

    public int $feedClaimSeconds;

    public int $projectTickSeconds;

    public int $projectBatch;

    public int $projectClaimSeconds;

    public function __construct(Repository $config)
    {
        $daemons = $config->get('process.daemons');
        self::closed($daemons, ['heartbeat_seconds', 'live_seconds', 'max_runtime_seconds', 'memory_limit_mb', 'error_backoff_min_seconds', 'error_backoff_max_seconds', 'relay', 'reconcile', 'feed', 'project'], 'process.daemons');
        $this->heartbeatSeconds = self::positive($daemons['heartbeat_seconds'], 'process.daemons.heartbeat_seconds');
        $this->liveSeconds = self::positive($daemons['live_seconds'], 'process.daemons.live_seconds');
        $this->maxRuntimeSeconds = self::positive($daemons['max_runtime_seconds'], 'process.daemons.max_runtime_seconds');
        $this->memoryLimitMb = self::positive($daemons['memory_limit_mb'], 'process.daemons.memory_limit_mb');
        $this->errorBackoffMinSeconds = self::positive($daemons['error_backoff_min_seconds'], 'process.daemons.error_backoff_min_seconds');
        $this->errorBackoffMaxSeconds = self::positive($daemons['error_backoff_max_seconds'], 'process.daemons.error_backoff_max_seconds');
        // Свежий heartbeat обязан пережить минимум два пропущенных интервала, иначе живой daemon мигает Down.
        if ($this->liveSeconds < 2 * $this->heartbeatSeconds) {
            throw new \InvalidArgumentException('Invalid daemon policy: process.daemons.live_seconds');
        }
        if ($this->errorBackoffMaxSeconds < $this->errorBackoffMinSeconds) {
            throw new \InvalidArgumentException('Invalid daemon policy: process.daemons.error_backoff_max_seconds');
        }

        $relay = $daemons['relay'];
        self::closed($relay, ['batch', 'claim_seconds', 'idle_min_ms', 'idle_max_ms'], 'process.daemons.relay');
        $this->relayBatch = self::positive($relay['batch'], 'process.daemons.relay.batch');
        $this->relayClaimSeconds = self::positive($relay['claim_seconds'], 'process.daemons.relay.claim_seconds');
        $this->relayIdleMinMs = self::positive($relay['idle_min_ms'], 'process.daemons.relay.idle_min_ms');
        $this->relayIdleMaxMs = self::positive($relay['idle_max_ms'], 'process.daemons.relay.idle_max_ms');
        if ($this->relayIdleMaxMs < $this->relayIdleMinMs) {
            throw new \InvalidArgumentException('Invalid daemon policy: process.daemons.relay.idle_max_ms');
        }

        $reconcile = $daemons['reconcile'];
        self::closed($reconcile, ['tick_seconds', 'jitter_percent'], 'process.daemons.reconcile');
        $this->reconcileTickSeconds = self::positive($reconcile['tick_seconds'], 'process.daemons.reconcile.tick_seconds');
        $this->reconcileJitterPercent = self::jitter($reconcile['jitter_percent'], 'process.daemons.reconcile.jitter_percent');

        $feed = $daemons['feed'];
        self::closed($feed, ['tick_seconds', 'jitter_percent', 'poll_ms', 'wake_batch', 'claim_seconds'], 'process.daemons.feed');
        $this->feedTickSeconds = self::positive($feed['tick_seconds'], 'process.daemons.feed.tick_seconds');
        $this->feedJitterPercent = self::jitter($feed['jitter_percent'], 'process.daemons.feed.jitter_percent');
        $this->feedPollMs = self::positive($feed['poll_ms'], 'process.daemons.feed.poll_ms');
        $this->feedWakeBatch = self::positive($feed['wake_batch'], 'process.daemons.feed.wake_batch');
        $this->feedClaimSeconds = self::positive($feed['claim_seconds'], 'process.daemons.feed.claim_seconds');

        $project = $daemons['project'];
        self::closed($project, ['tick_seconds', 'batch', 'claim_seconds'], 'process.daemons.project');
        $this->projectTickSeconds = self::positive($project['tick_seconds'], 'process.daemons.project.tick_seconds');
        $this->projectBatch = self::positive($project['batch'], 'process.daemons.project.batch');
        $this->projectClaimSeconds = self::positive($project['claim_seconds'], 'process.daemons.project.claim_seconds');
    }

    /**
     * Интервал с равномерным jitter ±percent: tick не синхронизируется между instances.
     *
     * @return int миллисекунды
     */
    public static function jittered(int $seconds, int $percent): int
    {
        $base = $seconds * 1000;
        $spread = intdiv($base * $percent, 100);

        return random_int($base - $spread, $base + $spread);
    }

    /** Экспоненциальный backoff 1→30 s: min · 2^(failures−1), не больше max. */
    public function backoffMs(int $consecutiveFailures): int
    {
        $exponent = min(max($consecutiveFailures, 1) - 1, 16);

        return min($this->errorBackoffMaxSeconds, $this->errorBackoffMinSeconds * (2 ** $exponent)) * 1000;
    }

    /**
     * @param  list<string>  $keys
     *
     * @phpstan-assert array<string, mixed> $value
     */
    private static function closed(mixed $value, array $keys, string $path): void
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException('Invalid daemon policy: '.$path);
        }
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new \InvalidArgumentException('Invalid daemon policy: '.$path);
        }
    }

    private static function positive(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('Invalid daemon policy: '.$path);
        }

        return $value;
    }

    private static function jitter(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 0 || $value > 50) {
            throw new \InvalidArgumentException('Invalid daemon policy: '.$path);
        }

        return $value;
    }
}
