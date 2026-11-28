<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Support\Time\Monotonic;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use stdClass;
use Throwable;

final readonly class PgTimeoutGuard
{
    public function __construct(
        private ConnectionResolverInterface $database,
    ) {}

    /**
     * @template T
     *
     * @param  callable(Connection): T  $callback
     * @return T
     */
    public function runWithPgTimeouts(int $deadlineNs, callable $callback, ?string $connectionName = null): mixed
    {
        if (Monotonic::nanoseconds() >= $deadlineNs) {
            throw new RuntimeException('Database readiness deadline exceeded');
        }

        $connection = $this->database->connection($connectionName);

        if (! $connection instanceof Connection) {
            throw new RuntimeException('Database connection is unavailable');
        }

        if ($connection->getDriverName() !== 'pgsql') {
            return $callback($connection);
        }

        $probe = function () use ($connection, $deadlineNs, $callback): mixed {
            $ms = $this->remainingBudgetMs($deadlineNs);
            $connection->statement(query: "SET LOCAL lock_timeout = {$ms}");
            $connection->statement(query: "SET LOCAL statement_timeout = {$ms}");

            return $callback($connection);
        };

        if ($connection->transactionLevel() === 0) {
            return $connection->transaction($probe);
        }

        // Keep Laravel's transaction level and callbacks owned by the caller.
        $connection->statement('SAVEPOINT agelto_readiness_timeouts');
        try {
            $original = $connection->selectOne(
                query: "SELECT current_setting('lock_timeout') AS lock_timeout, current_setting('statement_timeout') AS statement_timeout",
                useReadPdo: false,
            );
            if (! $original instanceof stdClass
                || ! is_string($original->lock_timeout ?? null)
                || ! is_string($original->statement_timeout ?? null)) {
                throw new RuntimeException('Could not read database readiness timeouts');
            }

            $result = $probe();
            $connection->selectOne(
                query: "SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)",
                bindings: [$original->lock_timeout, $original->statement_timeout],
                useReadPdo: false,
            );

            return $result;
        } catch (Throwable $failure) {
            $connection->statement('ROLLBACK TO SAVEPOINT agelto_readiness_timeouts');

            throw $failure;
        } finally {
            $connection->statement('RELEASE SAVEPOINT agelto_readiness_timeouts');
        }
    }

    /**
     * AzGuard owns its read transaction. Apply the timeout on the authority connection
     * before it takes a snapshot, then restore the session setting for the next caller.
     *
     * @param  callable(): void  $callback
     */
    public function runAuthorityRead(int $deadlineNs, callable $callback): void
    {
        $name = config('azguard.storages.default.connection');
        if ($name !== null && ! is_string($name)) {
            throw new RuntimeException('Invalid authority connection name');
        }

        $connection = $this->database->connection($name);
        if (! $connection instanceof Connection || Monotonic::nanoseconds() >= $deadlineNs) {
            throw new RuntimeException('Authority readiness unavailable');
        }

        if ($connection->getDriverName() !== 'pgsql') {
            $callback();

            return;
        }

        if ($connection->transactionLevel() > 0) {
            $this->runWithPgTimeouts($deadlineNs, $callback, $name);

            return;
        }

        $original = $connection->scalar("SELECT current_setting('statement_timeout')", useReadPdo: false);
        if (! is_string($original)) {
            throw new RuntimeException('Could not read authority statement timeout');
        }

        $connection->statement('SET statement_timeout = '.$this->remainingBudgetMs($deadlineNs));
        try {
            $callback();
        } finally {
            $connection->selectOne("SELECT set_config('statement_timeout', ?, false)", [$original], useReadPdo: false);
        }
    }

    private function remainingBudgetMs(int $deadlineNs): int
    {
        return max(1, (int) ceil(($deadlineNs - Monotonic::nanoseconds()) / 1_000_000));
    }
}
