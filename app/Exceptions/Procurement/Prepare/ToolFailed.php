<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Prepare;

final class ToolFailed extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $reason = null,
        public readonly ?int $exitCode = null,
    ) {
        parent::__construct($message);
    }
}
