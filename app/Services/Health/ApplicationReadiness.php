<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Dto\Health\ReadinessCheckResult;
use App\Enums\Health\ReadinessCheckCode;
use App\Services\Procurement\Process\DaemonReadiness;
use Carbon\CarbonImmutable;
use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

final readonly class ApplicationReadiness
{
    public const string SchedulerHeartbeatKey = 'agelto:readiness:scheduler-heartbeat';

    public function __construct(
        private ConnectionResolverInterface $database,
        private CacheFactory $cache,
        private FilesystemFactory $filesystem,
        private MasterSupervisorRepository $masters,
        private ReleaseStateReadiness $releaseState,
        private DaemonReadiness $daemons,
    ) {}

    /**
     * @param  list<ReadinessCheckCode>|null  $only
     * @return list<ReadinessCheckResult>
     */
    public function check(?array $only = null, ?int $deadlineNs = null): array
    {
        $deadlineNs ??= hrtime(true) + (self::positiveIntConfig('readiness.timeout_seconds') * 1_000_000_000);
        $codes = self::resolveCodes($only);

        $results = [];
        foreach ($codes as $code) {
            if (hrtime(true) >= $deadlineNs) {
                $results[] = new ReadinessCheckResult(code: $code, passed: false, message: 'unavailable');

                continue;
            }

            $result = match ($code) {
                ReadinessCheckCode::Database => $this->database($deadlineNs),
                ReadinessCheckCode::Cache => $this->cache($deadlineNs),
                ReadinessCheckCode::Storage => $this->storage($deadlineNs),
                ReadinessCheckCode::ReleaseState => $this->releaseState($deadlineNs),
                ReadinessCheckCode::Assets => $this->assets(),
                ReadinessCheckCode::Horizon => $this->horizon($deadlineNs),
                ReadinessCheckCode::Scheduler => $this->scheduler($deadlineNs),
                ReadinessCheckCode::ProcessDaemons => $this->processDaemons($deadlineNs),
            };

            if ($result->passed && hrtime(true) > $deadlineNs) {
                $result = new ReadinessCheckResult(code: $code, passed: false, message: 'unavailable');
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param  list<ReadinessCheckCode>|null  $only
     * @param  list<string>|null  $commandOverride
     * @return list<ReadinessCheckResult>
     */
    public function checkIsolated(?array $only = null, ?float $timeoutSeconds = null, ?array $commandOverride = null): array
    {
        $codes = self::resolveCodes($only);
        if ($codes === []) {
            return [];
        }

        $timeout = $timeoutSeconds ?? (float) self::positiveIntConfig('readiness.timeout_seconds');
        if ($timeout <= 0) {
            return self::unavailableResults($codes);
        }

        $command = $commandOverride ?? [
            PHP_BINARY,
            base_path('artisan'),
            'app:readiness',
            '--format=json',
            ...($only !== null ? ['--only='.implode(',', array_map(static fn (ReadinessCheckCode $c): string => $c->value, $only))] : []),
        ];
        if ($command === []) {
            return self::unavailableResults($codes);
        }

        $spawnCommand = is_executable('/usr/bin/setsid') ? ['/usr/bin/setsid', ...$command] : $command;

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $process = @proc_open($spawnCommand, $descriptors, $pipes, base_path());
        if (! is_resource($process)) {
            return self::unavailableResults($codes);
        }

        $stdoutPipe = $pipes[1];
        stream_set_blocking($stdoutPipe, false);

        $deadlineNs = hrtime(true) + max(1, (int) round($timeout * 1_000_000_000));
        $pid = null;
        $stdout = '';
        $timedOut = false;
        $exitCode = null;
        /** @var array<int, int> $trackedPids */
        $trackedPids = [];

        try {
            $status = proc_get_status($process);
            $pid = $status['pid'] > 0 ? $status['pid'] : null;
            if ($pid !== null) {
                $trackedPids[$pid] = $pid;
            }

            while (true) {
                $chunk = fread($stdoutPipe, 8192);
                if (is_string($chunk) && $chunk !== '' && strlen($stdout) < 65536) {
                    $stdout .= substr($chunk, 0, 65536 - strlen($stdout));
                }

                $status = proc_get_status($process);
                if ($pid !== null) {
                    foreach (self::collectDescendants($pid) as $descendantPid) {
                        $trackedPids[$descendantPid] = $descendantPid;
                    }
                }

                if (! $status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }

                if (hrtime(true) >= $deadlineNs) {
                    $timedOut = true;
                    break;
                }

                usleep(10_000);
            }

            if (! $timedOut && strlen($stdout) < 65536) {
                $chunk = stream_get_contents($stdoutPipe, 65536 - strlen($stdout));
                if (is_string($chunk) && $chunk !== '') {
                    $stdout .= $chunk;
                }
            }
        } finally {
            if (is_resource($stdoutPipe)) {
                fclose($stdoutPipe);
            }
            self::terminateAndReapProcessGroup($process, $pid, $trackedPids);
        }

        if ($timedOut || hrtime(true) > $deadlineNs || ! in_array($exitCode, [0, 1], true)) {
            return self::unavailableResults($codes);
        }

        $decoded = json_decode(trim($stdout), true);
        if (! is_array($decoded) || ! array_is_list($decoded) || count($decoded) !== count($codes)) {
            return self::unavailableResults($codes);
        }

        $results = [];
        foreach ($codes as $index => $code) {
            $item = $decoded[$index] ?? null;
            if (! is_array($item)
                || ($item['code'] ?? null) !== $code->value
                || ! is_bool($item['passed'] ?? null)
                || ! is_string($item['message'] ?? null)) {
                return self::unavailableResults($codes);
            }

            $passed = $item['passed'] === true && $item['message'] === 'ready';
            $results[] = new ReadinessCheckResult(
                code: $code,
                passed: $passed,
                message: $passed ? 'ready' : 'unavailable',
            );
        }

        return $results;
    }

    public function isGreen(): bool
    {
        foreach ($this->check() as $result) {
            if (! $result->passed) {
                return false;
            }
        }

        return true;
    }

    public static function recordSchedulerHeartbeat(): void
    {
        $store = config(key: 'readiness.scheduler.store');

        Cache::store(is_string($store) && $store !== '' ? $store : null)->put(
            key: self::SchedulerHeartbeatKey,
            value: now()->toIso8601String(),
            ttl: now()->addMinutes(self::positiveIntConfig('readiness.scheduler.ttl_minutes')),
        );
    }

    private function database(int $deadlineNs): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Database, callback: function () use ($deadlineNs): void {
            $this->runWithPgTimeouts($deadlineNs, function (Connection $connection): void {
                $connection->select(query: 'select 1');
            });
        });
    }

    private function cache(int $deadlineNs): ReadinessCheckResult
    {
        $key = 'agelto:readiness:'.bin2hex(random_bytes(16));
        $value = bin2hex(random_bytes(16));

        return $this->attempt(code: ReadinessCheckCode::Cache, callback: function () use ($key, $value, $deadlineNs): void {
            $written = false;
            try {
                $store = $this->cache->store();
                $store->put(key: $key, value: $value, ttl: self::positiveIntConfig('readiness.cache.ttl_seconds'));
                $written = true;
                if (hrtime(true) >= $deadlineNs) {
                    throw new \RuntimeException('cache readiness deadline exceeded');
                }
                $storedValue = $store->get(key: $key);

                if (! is_string($storedValue) || ! hash_equals($value, $storedValue)) {
                    throw new \RuntimeException('cache round-trip mismatch');
                }
            } finally {
                if ($written && hrtime(true) < $deadlineNs) {
                    try {
                        $this->cache->store()->forget(key: $key);
                    } catch (Throwable) {
                    }
                }
            }
        });
    }

    private function storage(int $deadlineNs): ReadinessCheckResult
    {
        $path = 'health/readiness-'.bin2hex(random_bytes(16)).'.txt';
        $value = bin2hex(random_bytes(16));

        return $this->attempt(code: ReadinessCheckCode::Storage, callback: function () use ($path, $value, $deadlineNs): void {
            $disk = self::nonEmptyStringConfig('readiness.storage.disk');
            $filesystem = $this->filesystem->disk(name: $disk);
            $written = false;

            try {
                if (hrtime(true) >= $deadlineNs) {
                    throw new \RuntimeException('storage readiness deadline exceeded');
                }
                if (! $filesystem->put(path: $path, contents: $value)) {
                    throw new \RuntimeException('storage round-trip mismatch');
                }
                $written = true;
                if (hrtime(true) >= $deadlineNs || ! hash_equals($value, (string) $filesystem->get(path: $path))) {
                    throw new \RuntimeException('storage round-trip mismatch');
                }
            } finally {
                if ($written && hrtime(true) < $deadlineNs) {
                    try {
                        $filesystem->delete(paths: $path);
                    } catch (Throwable) {
                    }
                }
            }
        });
    }

    private function releaseState(int $deadlineNs): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::ReleaseState, callback: function () use ($deadlineNs): void {
            $this->runWithPgTimeouts($deadlineNs, function (): void {
                if (! $this->releaseState->baseDataIsReady() || ! $this->releaseState->initialAdminIsReady()) {
                    throw new \RuntimeException('base data or initial administrator is not ready');
                }
            });
        });
    }

    private function assets(): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Assets, callback: function (): void {
            $assets = [...FilamentAsset::getScripts(), ...FilamentAsset::getStyles()];

            if ($assets === [] || collect($assets)
                ->filter(fn (Asset $asset): bool => ! $asset->isRemote())
                ->contains(fn (Asset $asset): bool => ! is_file($asset->getPublicPath()))) {
                throw new \RuntimeException('Filament asset is missing');
            }
        });
    }

    private function horizon(int $deadlineNs): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Horizon, callback: function () use ($deadlineNs): void {
            if (hrtime(true) >= $deadlineNs) {
                throw new \RuntimeException('Horizon readiness deadline exceeded');
            }
            $masters = $this->masters->all();

            if ($masters === [] || collect($masters)->contains(static fn (mixed $master): bool => self::horizonMasterIsNotRunning($master))) {
                throw new \RuntimeException('Horizon is not running');
            }
        });
    }

    private function scheduler(int $deadlineNs): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Scheduler, callback: function () use ($deadlineNs): void {
            if (hrtime(true) >= $deadlineNs) {
                throw new \RuntimeException('scheduler readiness deadline exceeded');
            }
            $store = config(key: 'readiness.scheduler.store');
            $heartbeat = $this->cache->store(is_string($store) && $store !== '' ? $store : null)->get(key: self::SchedulerHeartbeatKey);

            if (! is_string($heartbeat)
                || CarbonImmutable::parse($heartbeat)->lt(now()->subMinutes(self::positiveIntConfig('readiness.scheduler.max_age_minutes')))) {
                throw new \RuntimeException('scheduler heartbeat is stale');
            }
        });
    }

    private function processDaemons(int $deadlineNs): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::ProcessDaemons, callback: function () use ($deadlineNs): void {
            if (hrtime(true) >= $deadlineNs) {
                throw new \RuntimeException('Process daemons readiness deadline exceeded');
            }

            $this->runWithPgTimeouts($deadlineNs, function (): void {
                if (! $this->daemons->ready()) {
                    throw new \RuntimeException('Process daemons are not ready');
                }
            });
        });
    }

    /**
     * @param  callable(Connection): void  $callback
     */
    private function runWithPgTimeouts(int $deadlineNs, callable $callback): void
    {
        if (hrtime(true) >= $deadlineNs) {
            throw new \RuntimeException('Database readiness deadline exceeded');
        }

        $connection = $this->database->connection();

        if (! $connection instanceof Connection) {
            throw new \RuntimeException('Database connection is unavailable');
        }

        if ($connection->getDriverName() !== 'pgsql') {
            $callback($connection);

            return;
        }

        $probe = function () use ($connection, $deadlineNs, $callback): void {
            $ms = $this->remainingBudgetMs($deadlineNs);
            $connection->statement(query: "SET LOCAL lock_timeout = {$ms}");
            $connection->statement(query: "SET LOCAL statement_timeout = {$ms}");
            $callback($connection);
        };

        if ($connection->transactionLevel() === 0) {
            $connection->transaction($probe);

            return;
        }

        // Keep Laravel's transaction level and callbacks owned by the caller.
        $connection->statement('SAVEPOINT agelto_readiness_timeouts');
        try {
            $original = $connection->selectOne(
                query: "SELECT current_setting('lock_timeout') AS lock_timeout, current_setting('statement_timeout') AS statement_timeout",
                useReadPdo: false,
            );
            if (! $original instanceof \stdClass
                || ! is_string($original->lock_timeout ?? null)
                || ! is_string($original->statement_timeout ?? null)) {
                throw new \RuntimeException('Could not read database readiness timeouts');
            }

            $probe();
            $connection->selectOne(
                query: "SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)",
                bindings: [$original->lock_timeout, $original->statement_timeout],
                useReadPdo: false,
            );
        } catch (Throwable $failure) {
            $connection->statement('ROLLBACK TO SAVEPOINT agelto_readiness_timeouts');

            throw $failure;
        } finally {
            $connection->statement('RELEASE SAVEPOINT agelto_readiness_timeouts');
        }
    }

    private function remainingBudgetMs(int $deadlineNs): int
    {
        return max(1, (int) ceil(($deadlineNs - hrtime(true)) / 1_000_000));
    }

    /** @param callable(): void $callback */
    private function attempt(ReadinessCheckCode $code, callable $callback): ReadinessCheckResult
    {
        try {
            $callback();
        } catch (Throwable) {
            return new ReadinessCheckResult(code: $code, passed: false, message: 'unavailable');
        }

        return new ReadinessCheckResult(code: $code, passed: true, message: 'ready');
    }

    private static function positiveIntConfig(string $key): int
    {
        $value = config(key: $key);

        if (! is_int($value) || $value <= 0) {
            throw new \LogicException("{$key} должен быть положительным целым числом");
        }

        return $value;
    }

    private static function nonEmptyStringConfig(string $key): string
    {
        $value = config(key: $key);

        if (! is_string($value) || $value === '') {
            throw new \LogicException("{$key} должен быть непустой строкой");
        }

        return $value;
    }

    private static function horizonMasterIsNotRunning(mixed $master): bool
    {
        if (! is_object($master)) {
            return true;
        }

        $status = get_object_vars($master)['status'] ?? null;

        return $status !== 'running';
    }

    /**
     * @param  list<ReadinessCheckCode>|null  $only
     * @return list<ReadinessCheckCode>
     */
    private static function resolveCodes(?array $only): array
    {
        $all = [
            ReadinessCheckCode::Database,
            ReadinessCheckCode::Cache,
            ReadinessCheckCode::Storage,
            ReadinessCheckCode::ReleaseState,
            ReadinessCheckCode::Assets,
            ReadinessCheckCode::Horizon,
            ReadinessCheckCode::Scheduler,
            ReadinessCheckCode::ProcessDaemons,
        ];

        return $only === null
            ? $all
            : array_values(array_filter($all, static fn (ReadinessCheckCode $code): bool => in_array($code, $only, true)));
    }

    /**
     * @param  list<ReadinessCheckCode>  $codes
     * @return list<ReadinessCheckResult>
     */
    private static function unavailableResults(array $codes): array
    {
        return array_map(
            static fn (ReadinessCheckCode $code): ReadinessCheckResult => new ReadinessCheckResult(
                code: $code,
                passed: false,
                message: 'unavailable',
            ),
            $codes,
        );
    }

    /**
     * @param  resource  $process
     * @param  array<int, int>  $trackedPids
     */
    private static function terminateAndReapProcessGroup(mixed $process, ?int $pid, array $trackedPids): void
    {
        if ($pid !== null) {
            foreach (array_values($trackedPids) as $rootPid) {
                foreach (self::collectDescendants($rootPid) as $descendantPid) {
                    $trackedPids[$descendantPid] = $descendantPid;
                }
            }

            if (function_exists('posix_kill')) {
                @posix_kill(-$pid, 15);
                foreach ($trackedPids as $targetPid) {
                    @posix_kill($targetPid, 15);
                }
                $graceUntilNs = hrtime(true) + 100_000_000;
                while (hrtime(true) < $graceUntilNs && self::anyPidPresent($trackedPids)) {
                    usleep(10_000);
                }

                foreach (array_values($trackedPids) as $rootPid) {
                    foreach (self::collectDescendants($rootPid) as $descendantPid) {
                        $trackedPids[$descendantPid] = $descendantPid;
                    }
                }

                @posix_kill(-$pid, 9);
                foreach ($trackedPids as $targetPid) {
                    @posix_kill($targetPid, 9);
                }
            }
        }

        @proc_close($process);

        if ($trackedPids !== []) {
            $reapUntilNs = hrtime(true) + 800_000_000;
            while (hrtime(true) < $reapUntilNs && self::anyPidPresent($trackedPids)) {
                if (function_exists('posix_kill')) {
                    if ($pid !== null) {
                        @posix_kill(-$pid, 9);
                    }
                    foreach ($trackedPids as $targetPid) {
                        @posix_kill($targetPid, 9);
                    }
                }
                usleep(10_000);
            }
        }
    }

    /**
     * @param  array<int, int>  $pids
     */
    private static function anyPidPresent(array $pids): bool
    {
        foreach ($pids as $pid) {
            if (self::isPidAlive($pid)) {
                return true;
            }
        }

        return false;
    }

    private static function isPidAlive(int $pid): bool
    {
        $status = @file_get_contents("/proc/{$pid}/status");
        if (is_string($status)) {
            return preg_match('/^State:\s+Z\b/m', $status) !== 1;
        }

        return is_dir("/proc/{$pid}") || (function_exists('posix_kill') && @posix_kill($pid, 0));
    }

    /**
     * @return list<int>
     */
    private static function collectDescendants(int $rootPid): array
    {
        $selfPid = getmypid() ?: 0;
        $queue = self::directChildren($rootPid);
        $visited = [];
        $order = [];

        while ($queue !== []) {
            $pid = array_shift($queue);
            if ($pid === $selfPid || isset($visited[$pid])) {
                continue;
            }
            $visited[$pid] = true;
            $order[] = $pid;
            foreach (self::directChildren($pid) as $childPid) {
                $queue[] = $childPid;
            }
        }

        return $order;
    }

    /**
     * @return list<int>
     */
    private static function directChildren(int $pid): array
    {
        $taskDir = "/proc/{$pid}/task";
        $tids = @scandir($taskDir);
        if (! is_array($tids)) {
            return [];
        }

        $children = [];
        foreach ($tids as $tid) {
            if ($tid === '.' || $tid === '..') {
                continue;
            }
            $raw = @file_get_contents("{$taskDir}/{$tid}/children");
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            foreach (preg_split('/\s+/', trim($raw)) ?: [] as $token) {
                $childPid = (int) $token;
                if ($childPid > 0) {
                    $children[] = $childPid;
                }
            }
        }

        return $children;
    }
}
