<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

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
