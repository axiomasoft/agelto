<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\HandlerEligibility;
use App\Enums\Procurement\Process\StageCode;

final readonly class HandlerAuthoritySnapshot
{
    public function __construct(public ProcessUuid $id, public StageCode $stage, public string $handlerVersion, public HandlerEligibility $eligibility, public int $version)
    {
        ProcessValue::text($handlerVersion);
        ProcessValue::positive($version);
    }
}
