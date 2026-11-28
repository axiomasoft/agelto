<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Validation;

use InvalidArgumentException;

/**
 * Отдельное замечание или ошибка валидации набора правил парсинга.
 */
final readonly class RuleValidationIssue
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $path,
        public string $message,
    ) {
        if ($severity !== 'error' && $severity !== 'warning') {
            throw new InvalidArgumentException('RuleValidationIssue severity must be error or warning.');
        }
    }

    /**
     * @return array{severity: string, code: string, path: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'path' => $this->path,
            'message' => $this->message,
        ];
    }
}
