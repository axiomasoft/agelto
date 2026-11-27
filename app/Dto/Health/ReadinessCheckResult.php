<?php

declare(strict_types=1);

namespace App\Dto\Health;

use App\Enums\Health\ReadinessCheckCode;

final readonly class ReadinessCheckResult
{
    public function __construct(
        public ReadinessCheckCode $code,
        public bool $passed,
        public string $message,
    ) {}

    /** @return array{code: string, passed: bool, message: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'passed' => $this->passed,
            'message' => $this->message,
        ];
    }
}
