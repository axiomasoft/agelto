<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process\Support;

use App\Support\Database\TransactionGuard;

/**
 * Ledger writers участвуют в транзакции вызывающего (launch/finalize), а не открывают свою:
 * state + transition + projection outbox коммитятся вместе, ID создаются до транзакции.
 */
final class CallerTransaction
{
    public static function require(string $operation): void
    {
        TransactionGuard::requireActive($operation);
    }

    public static function forbid(string $operation): void
    {
        TransactionGuard::forbidActive($operation);
    }
}
