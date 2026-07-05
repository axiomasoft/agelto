<?php

declare(strict_types=1);

namespace App\Services\Crawler\Exceptions;

use RuntimeException;

/**
 * Часть контракта `SourceMethod`, ещё не реализованная на этом этапе плана
 * (например `CsvSourceMethod::collectDetails()` — задел фазы P3).
 */
final class NotImplementedException extends RuntimeException
{
    public static function forMethod(string $method): self
    {
        return new self(
            message: "Method [{$method}] is not implemented yet.",
        );
    }
}
