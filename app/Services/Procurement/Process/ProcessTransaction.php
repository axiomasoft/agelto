<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Единственная обёртка Process tx: READ COMMITTED, lock_timeout 2s,
 * statement_timeout 5s; до 3 попыток только на 40P01/40001/55P03 с jitter 20–200 ms. Повторяется
 * лишь SQL closure: event/message IDs создаёт caller до run, сети и handler внутри нет.
 * Вложенный вызов (уже открытая tx caller) не повторяется: решение о retry принадлежит владельцу
 * внешней транзакции. Constraint/schema/programming SQL errors не маскируются.
 */
final readonly class ProcessTransaction
{
    private const array RETRYABLE = ['40P01', '40001', '55P03'];

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(Closure $operation, int $maxAttempts = 3): mixed
    {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Process transaction needs at least one attempt.');
        }
        $nested = DB::connection()->transactionLevel() > 0;
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(static function () use ($operation): mixed {
                    DB::statement("SET LOCAL lock_timeout = '2s'");
                    DB::statement("SET LOCAL statement_timeout = '5s'");

                    return $operation();
                }, attempts: 1);
            } catch (QueryException $exception) {
                $sqlState = $exception->errorInfo[0] ?? null;
                if ($nested || $attempt >= $maxAttempts || ! is_string($sqlState) || ! in_array($sqlState, self::RETRYABLE, true)) {
                    throw $exception;
                }
                usleep(random_int(20_000, 200_000));
            }
        }
    }
}
