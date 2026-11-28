<?php

declare(strict_types=1);

namespace App\Support\Database;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Единая проверка границ транзакций БД: обязательное наличие транзакции для ledger-записей
 * и запрет активной транзакции для внешнего I/O (object storage, receipts, cutover waits).
 */
final readonly class TransactionGuard
{
    public static function requireActive(string $operation, ?ConnectionInterface $connection = null): void
    {
        $db = $connection ?? DB::connection();
        if ($db->transactionLevel() === 0) {
            throw new LogicException($operation.' must run inside the caller ledger transaction.');
        }
    }

    public static function forbidActive(string $operation, ?ConnectionInterface $connection = null, ?string $message = null): void
    {
        $db = $connection ?? DB::connection();
        if ($db->transactionLevel() !== 0) {
            throw new DomainException($message ?? ($operation.' must run outside database transactions.'));
        }
    }
}
