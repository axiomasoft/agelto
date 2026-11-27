<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Inputs;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class StatusRefreshInput implements WorkInput
{
    public function __construct(
        public int $procurementId,
        public int $cycleSeq,
        public ContentHash $statusPolicyHash,
        public string $adapterVersion,
    ) {
        ProcessValue::positive($procurementId);
        ProcessValue::positive($cycleSeq);
        ProcessValue::text($adapterVersion);
    }

    public function stage(): StageCode
    {
        return StageCode::StatusRefresh;
    }
}
