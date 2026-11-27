<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Dto\Health\ReadinessCheckResult;
use App\Enums\Health\ReadinessCheckCode;
use App\Services\PartitionCoverage;
use App\Services\Procurement\Process\Daemons\DaemonReadiness;
use App\Support\Config\TypedConfig;
use Carbon\CarbonImmutable;
use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use RuntimeException;
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
        private ?PartitionCoverage $partitions = null,
        private ?IsolatedReadinessProcess $isolatedProcess = null,
        private ?PgTimeoutGuard $pgTimeoutGuard = null,
    ) {}

    /**
     * @param  list<ReadinessCheckCode>|null  $only
     * @return list<ReadinessCheckResult>
     */
    public function check(?array $only = null, ?int $deadlineNs = null): array
    {
        $deadlineNs ??= hrtime(true) + (TypedConfig::positiveInt('readiness.timeout_seconds') * 1_000_000_000);
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
        return ($this->isolatedProcess ?? new IsolatedReadinessProcess)->checkIsolated(
            codes: self::resolveCodes($only),
            only: $only,
            timeoutSeconds: $timeoutSeconds,
            commandOverride: $commandOverride,
        );
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
            ttl: now()->addMinutes(TypedConfig::positiveInt('readiness.scheduler.ttl_minutes')),
        );
    }

    private function database(int $deadlineNs): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Database, callback: function () use ($deadlineNs): void {
            $this->pgTimeoutGuard()->runWithPgTimeouts($deadlineNs, function (Connection $connection): void {
                $connection->select(query: 'select 1');

                if ($connection->getDriverName() !== 'pgsql') {
                    return;
                }

                $tables = config(key: 'partitioning.tables', default: []);
                if (! is_array($tables)) {
                    throw new RuntimeException('Invalid partitioning table configuration');
                }

                $now = now()->utc();
                $nextMonth = $now->copy()->startOfMonth()->addMonthNoOverflow();
                $partitions = $this->partitions ?? new PartitionCoverage;
                foreach (array_keys($tables) as $table) {
                    if (! is_string($table)
                        || ! $partitions->hasCoverageFor($table, $now)
                        || ! $partitions->hasCoverageFor($table, $nextMonth)) {
                        throw new RuntimeException("missing_partition:{$table}");
                    }
                }
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
                $store->put(key: $key, value: $value, ttl: TypedConfig::positiveInt('readiness.cache.ttl_seconds'));
                $written = true;
                if (hrtime(true) >= $deadlineNs) {
                    throw new RuntimeException('cache readiness deadline exceeded');
                }
                $storedValue = $store->get(key: $key);

                if (! is_string($storedValue) || ! hash_equals($value, $storedValue)) {
                    throw new RuntimeException('cache round-trip mismatch');
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
            $disk = TypedConfig::nonEmptyString('readiness.storage.disk');
            $filesystem = $this->filesystem->disk(name: $disk);
            $written = false;

            try {
                if (hrtime(true) >= $deadlineNs) {
                    throw new RuntimeException('storage readiness deadline exceeded');
                }
                if (! $filesystem->put(path: $path, contents: $value)) {
                    throw new RuntimeException('storage round-trip mismatch');
                }
                $written = true;
                if (hrtime(true) >= $deadlineNs || ! hash_equals($value, (string) $filesystem->get(path: $path))) {
                    throw new RuntimeException('storage round-trip mismatch');
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
            $this->pgTimeoutGuard()->runWithPgTimeouts($deadlineNs, function (): void {
                if (! $this->releaseState->baseDataIsReady() || ! $this->releaseState->initialAdminIsReady()) {
                    throw new RuntimeException('base data or initial administrator is not ready');
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
                throw new RuntimeException('Filament asset is missing');
            }
        });
    }

    private function horizon(int $deadlineNs): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Horizon, callback: function () use ($deadlineNs): void {
            if (hrtime(true) >= $deadlineNs) {
                throw new RuntimeException('Horizon readiness deadline exceeded');
            }
            $masters = $this->masters->all();

            if ($masters === [] || collect($masters)->contains(static fn (mixed $master): bool => self::horizonMasterIsNotRunning($master))) {
                throw new RuntimeException('Horizon is not running');
            }
        });
    }

    private function scheduler(int $deadlineNs): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Scheduler, callback: function () use ($deadlineNs): void {
            if (hrtime(true) >= $deadlineNs) {
                throw new RuntimeException('scheduler readiness deadline exceeded');
            }
            $store = config(key: 'readiness.scheduler.store');
            $heartbeat = $this->cache->store(is_string($store) && $store !== '' ? $store : null)->get(key: self::SchedulerHeartbeatKey);

            if (! is_string($heartbeat)
                || CarbonImmutable::parse($heartbeat)->lt(now()->subMinutes(TypedConfig::positiveInt('readiness.scheduler.max_age_minutes')))) {
                throw new RuntimeException('scheduler heartbeat is stale');
            }
        });
    }

    private function processDaemons(int $deadlineNs): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::ProcessDaemons, callback: function () use ($deadlineNs): void {
            if (hrtime(true) >= $deadlineNs) {
                throw new RuntimeException('Process daemons readiness deadline exceeded');
            }

            $this->pgTimeoutGuard()->runWithPgTimeouts($deadlineNs, function (): void {
                if (! $this->daemons->ready()) {
                    throw new RuntimeException('Process daemons are not ready');
                }
            });
        });
    }

    private function pgTimeoutGuard(): PgTimeoutGuard
    {
        return $this->pgTimeoutGuard ?? new PgTimeoutGuard($this->database);
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
}
