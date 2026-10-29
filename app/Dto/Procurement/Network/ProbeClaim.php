<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

final readonly class ProbeClaim
{
    /** @param array<string, int> $epochs Exact nonclosed scopes owned by this token. */
    public function __construct(public ProcessUuid $token, public array $epochs, public \DateTimeImmutable $until) {}
}
