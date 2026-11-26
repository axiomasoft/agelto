<?php

declare(strict_types=1);

namespace App\Dto\Llm\Schema;

final readonly class SchemaValidationResult
{
    /** @param list<string> $errors */
    public function __construct(public array $errors, public bool $splitRequired = false) {}

    public function valid(): bool
    {
        return $this->errors === [];
    }
}
