<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

/**
 * Transient DB failures: connection (08*), resources (53*), admin
 * shutdown/statement timeout (57P01–57P03, 57014). Constraint/schema/programming SQL errors не
 * маскируются под outage и пробрасываются видимо.
 */
final class DatabaseFailureClassifier
{
    private const array TRANSIENT = ['57P01', '57P02', '57P03', '57014'];

    public static function isTransient(\Throwable $failure): bool
    {
        // QueryException — подкласс PDOException: SQLSTATE в errorInfo[0], иначе в code.
        if (! $failure instanceof \PDOException) {
            return false;
        }
        $state = $failure->errorInfo[0] ?? $failure->getCode();
        if (! is_string($state) || strlen($state) !== 5) {
            return false;
        }

        return str_starts_with($state, '08') || str_starts_with($state, '53') || in_array($state, self::TRANSIENT, true);
    }
}
