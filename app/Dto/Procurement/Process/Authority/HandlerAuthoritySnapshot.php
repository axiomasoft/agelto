<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\HandlerEligibility;

final readonly class HandlerAuthoritySnapshot
{
    public function __construct(public ProcessUuid $id, public StageCode $stage, public string $handlerVersion, public HandlerEligibility $eligibility, public int $version)
    {
        ProcessValue::text($handlerVersion);
        ProcessValue::positive($version);
    }
}
