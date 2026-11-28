<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Parsing;

use App\Dto\Procurement\Parsing\Validation\RuleValidationResult;
use InvalidArgumentException;
use Throwable;

/**
 * Набор правил парсинга не прошёл структурную или семантическую валидацию.
 */
final class InvalidParsingRuleSetException extends InvalidArgumentException
{
    public function __construct(
        public readonly RuleValidationResult $result,
        string $message = 'Parsing rule set validation failed.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
