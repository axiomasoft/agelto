<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Prepare;

use RuntimeException;

/**
 * A prepare job has too little remaining time for the next required operation chain. This is
 * retriable capacity exhaustion, not evidence that the document is unprocessable.
 */
final class OperationBudgetExceeded extends RuntimeException
{
    public static function forOperation(string $operation, int $availableSeconds, int $requiredSeconds): self
    {
        return new self("Недостаточно времени для {$operation}: доступно {$availableSeconds} с, требуется {$requiredSeconds} с.");
    }
}
