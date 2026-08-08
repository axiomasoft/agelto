<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Health;

use App\Exceptions\Procurement\OperationFailed;

final class HealthBaselineUnavailableException extends OperationFailed
{
    public function __construct(string $reason)
    {
        parent::__construct("Эталон healthcheck недоступен: {$reason}.");
    }
}
