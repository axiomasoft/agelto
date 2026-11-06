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
    public function check(?array $only = null): array
    {
        $deadlineNs = hrtime(true) + (self::positiveIntConfig('readiness.timeout_seconds') * 1_000_000_000);
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
        $codes = $only === null
            ? $all
            : array_values(array_filter($all, static fn (ReadinessCheckCode $code): bool => in_array($code, $only, true)));

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
        Cache::put(
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
                if (! $filesystem->put(path: $path, contents: $value)) {
                    throw new \RuntimeException('storage round-trip mismatch');
                }
                $written = true;
                if (hrtime(true) >= $deadlineNs || ! hash_equals($value, (string) $filesystem->get(path: $path))) {
                    throw new \RuntimeException('storage round-trip mismatch');
                }
            } finally {
                if ($written) {
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
            $heartbeat = $this->cache->store()->get(key: self::SchedulerHeartbeatKey);

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
        $connection = $this->database->connection();

        if (! $connection instanceof Connection) {
            throw new \RuntimeException('Database connection is unavailable');
        }

        if ($connection->getDriverName() === 'pgsql') {
            $connection->transaction(function () use ($connection, $deadlineNs, $callback): void {
                $ms = $this->remainingBudgetMs($deadlineNs);
                $connection->statement("SET LOCAL lock_timeout = {$ms}");
                $connection->statement("SET LOCAL statement_timeout = {$ms}");
                $callback($connection);
            });

            return;
        }

        $callback($connection);
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
}
