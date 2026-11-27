<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Execution\QueueRoute;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Process\Infrastructure\ProcessPolicy;
use Illuminate\Contracts\Config\Repository;

/**
 * Единственный источник connection, queue и таймаутов доставки stage.
 * Connection/queue/job timeout берутся из process.queues, Horizon timeout — из фактического
 * supervisor этой connection+queue, retry_after — из фактической queue connection, slice и lease —
 * из ProcessPolicy. Любая stage обязана иметь ровно один маршрут, а цепочка
 * slice < job timeout < Horizon timeout < retry_after < lease — выполняться; иначе сборка
 * отвергается с ключом config, а не подставляет default (Redis visibility не должен вернуть ещё
 * работающий job второму воркеру). Relay и Job используют только эту карту.
 */
final readonly class StageQueueMap
{
    /** @var array<string, QueueRoute> */
    private array $routes;

    public function __construct(ProcessPolicy $policy, Repository $config)
    {
        $queues = $config->get('process.queues');
        if (! is_array($queues)) {
            throw new \InvalidArgumentException('Invalid process queue map: process.queues');
        }
        $routes = [];
        foreach (StageCode::cases() as $code) {
            $path = 'process.queues.'.$code->value;
            $entry = $queues[$code->value] ?? null;
            if (! is_array($entry) || array_keys($entry) !== ['connection', 'queue', 'job_timeout']
                || ! is_string($entry['connection']) || ! is_string($entry['queue']) || ! is_int($entry['job_timeout'])) {
                throw new \InvalidArgumentException('Invalid process queue map: '.$path);
            }
            try {
                $routes[$code->value] = new QueueRoute(
                    stage: $code,
                    connection: $entry['connection'],
                    queue: $entry['queue'],
                    sliceSeconds: $policy->sliceSeconds($code),
                    jobTimeout: $entry['job_timeout'],
                    horizonTimeout: self::horizonTimeout($config, $entry['connection'], $entry['queue'], $path),
                    retryAfter: self::retryAfter($config, $entry['connection'], $path),
                    leaseSeconds: $policy->leaseSeconds($code),
                );
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException('Invalid process queue map: '.$path, previous: $exception);
            }
        }
        if (count($queues) !== count($routes)) {
            throw new \InvalidArgumentException('Invalid process queue map: process.queues');
        }
        $this->routes = $routes;
    }

    /** Маршрут существует у каждой stage: отсутствие — дефект сборки, а не runtime default. */
    public function forStage(StageCode $stage): QueueRoute
    {
        return $this->routeForKey($stage->value);
    }

    private function routeForKey(string $key): QueueRoute
    {
        if (! array_key_exists($key, $this->routes)) {
            throw new \LogicException('Stage has no queue route: '.$key);
        }

        return $this->routes[$key];
    }

    /** @return list<QueueRoute> */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    private static function retryAfter(Repository $config, string $connection, string $path): int
    {
        $settings = $config->get('queue.connections.'.$connection);
        if (! is_array($settings) || ($settings['driver'] ?? null) !== 'redis' || ! is_int($settings['retry_after'] ?? null)) {
            throw new \InvalidArgumentException('Invalid process queue map: queue.connections.'.$connection.' for '.$path);
        }

        return $settings['retry_after'];
    }

    /** Ровно один supervisor обслуживает connection+queue; его tries=1 — retry принадлежит ledger. */
    private static function horizonTimeout(Repository $config, string $connection, string $queue, string $path): int
    {
        $supervisors = $config->get('horizon.defaults');
        $matches = [];
        foreach (is_array($supervisors) ? $supervisors : [] as $name => $supervisor) {
            if (is_array($supervisor) && ($supervisor['connection'] ?? null) === $connection
                && in_array($queue, (array) ($supervisor['queue'] ?? []), true)) {
                $matches[(string) $name] = $supervisor;
            }
        }
        if (count($matches) !== 1) {
            throw new \InvalidArgumentException('Invalid process queue map: horizon.defaults supervisor of '.$connection.':'.$queue.' for '.$path);
        }
        $supervisor = array_values($matches)[0];
        if (! is_int($supervisor['timeout'] ?? null) || ($supervisor['tries'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Invalid process queue map: horizon.defaults.'.array_key_first($matches).' timeout/tries');
        }

        return $supervisor['timeout'];
    }
}
