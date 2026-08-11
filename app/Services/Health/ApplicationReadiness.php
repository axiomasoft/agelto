<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Doctor\Diagnostics\BaseDataIsReady;
use App\Doctor\Diagnostics\InitialAdminIsReady;
use App\Dto\Health\ReadinessCheckResult;
use App\Enums\Health\ReadinessCheckCode;
use Carbon\CarbonImmutable;
use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Cache;
use Laravel\Doctor\Results\Status;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

final readonly class ApplicationReadiness
{
    public const SchedulerHeartbeatKey = 'agelto:readiness:scheduler-heartbeat';

    public function __construct(
        private ConnectionResolverInterface $database,
        private CacheFactory $cache,
        private FilesystemFactory $filesystem,
        private MasterSupervisorRepository $masters,
    ) {}

    /** @return list<ReadinessCheckResult> */
    public function check(): array
    {
        return [
            $this->database(),
            $this->cache(),
            $this->storage(),
            $this->releaseState(),
            $this->assets(),
            $this->horizon(),
            $this->scheduler(),
        ];
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
            ttl: now()->addMinutes((int) config(key: 'readiness.scheduler.ttl_minutes')),
        );
    }

    private function database(): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Database, callback: function (): void {
            $this->database->connection()->select(query: 'select 1');
        });
    }

    private function cache(): ReadinessCheckResult
    {
        $key = 'agelto:readiness:'.bin2hex(random_bytes(16));
        $value = bin2hex(random_bytes(16));

        return $this->attempt(code: ReadinessCheckCode::Cache, callback: function () use ($key, $value): void {
            try {
                $store = $this->cache->store();
                $store->put(key: $key, value: $value, ttl: (int) config(key: 'readiness.cache.ttl_seconds'));

                if (! hash_equals($value, (string) $store->get(key: $key))) {
                    throw new \RuntimeException('cache round-trip mismatch');
                }
            } finally {
                $this->cache->store()->forget(key: $key);
            }
        });
    }

    private function storage(): ReadinessCheckResult
    {
        $path = 'health/readiness-'.bin2hex(random_bytes(16)).'.txt';
        $value = bin2hex(random_bytes(16));
        $disk = (string) config(key: 'readiness.storage.disk');

        return $this->attempt(code: ReadinessCheckCode::Storage, callback: function () use ($disk, $path, $value): void {
            $filesystem = $this->filesystem->disk(name: $disk);

            try {
                if (! $filesystem->put(path: $path, contents: $value)
                    || ! hash_equals($value, (string) $filesystem->get(path: $path))) {
                    throw new \RuntimeException('storage round-trip mismatch');
                }
            } finally {
                $filesystem->delete(paths: $path);
            }
        });
    }

    private function releaseState(): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::ReleaseState, callback: function (): void {
            $base = app(BaseDataIsReady::class)->check();
            $admin = app(InitialAdminIsReady::class)->check();

            if ($base->status !== Status::Pass || $admin->status !== Status::Pass) {
                throw new \RuntimeException('base data or initial administrator is not ready');
            }
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

    private function horizon(): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Horizon, callback: function (): void {
            $masters = $this->masters->all();

            if ($masters === [] || collect($masters)->contains(fn (object $master): bool => $master->status !== 'running')) {
                throw new \RuntimeException('Horizon is not running');
            }
        });
    }

    private function scheduler(): ReadinessCheckResult
    {
        return $this->attempt(code: ReadinessCheckCode::Scheduler, callback: function (): void {
            $heartbeat = $this->cache->store()->get(key: self::SchedulerHeartbeatKey);

            if (! is_string($heartbeat)
                || CarbonImmutable::parse($heartbeat)->lt(now()->subMinutes((int) config(key: 'readiness.scheduler.max_age_minutes')))) {
                throw new \RuntimeException('scheduler heartbeat is stale');
            }
        });
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
}
