<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Governance;

use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Governance\OperatorCommandStatus;

/** Исход operator command; version/successor заданы только там, где команда их порождает. */
final readonly class OperatorCommandResult
{
    public function __construct(
        public OperatorCommandStatus $status,
        public ?int $controlVersion = null,
        public ?WorkId $successor = null,
    ) {}
}
