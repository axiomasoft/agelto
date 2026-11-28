<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Primitives;

final readonly class WorkId
{
    public function __construct(public ProcessUuid $value) {}
}
