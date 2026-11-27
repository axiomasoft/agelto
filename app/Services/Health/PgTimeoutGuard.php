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
     * @param  callable(Connection): void  $callback
     */
    public function runWithPgTimeouts(int $deadlineNs, callable $callback): void
    {
        if (Monotonic::nanoseconds() >= $deadlineNs) {
            throw new RuntimeException('Database readiness deadline exceeded');
        }

        $connection = $this->database->connection();

        if (! $connection instanceof Connection) {
            throw new RuntimeException('Database connection is unavailable');
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
            if (! $original instanceof stdClass
                || ! is_string($original->lock_timeout ?? null)
                || ! is_string($original->statement_timeout ?? null)) {
                throw new RuntimeException('Could not read database readiness timeouts');
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
        return max(1, (int) ceil(($deadlineNs - Monotonic::nanoseconds()) / 1_000_000));
    }
}
