<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use Illuminate\Support\Facades\DB;

/**
 * Ledger writers участвуют в транзакции вызывающего (launch/finalize), а не открывают свою:
 * state + transition + projection outbox коммитятся вместе, ID создаются до транзакции.
 */
final class CallerTransaction
{
    public static function require(string $operation): void
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new \LogicException($operation.' must run inside the caller ledger transaction.');
        }
    }
}
