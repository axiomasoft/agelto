<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

final readonly class DetailInput implements WorkInput
{
    public function __construct(
        public int $procurementId,
        public ProcessUuid $intentId,
        public int $expectedHeadGeneration,
        public ContentHash $semanticPolicyHash,
        public ContentHash $accessPolicyHash,
        public string $adapterVersion,
    ) {
        ProcessValue::positive($procurementId);
        ProcessValue::positive($expectedHeadGeneration);
        ProcessValue::text($adapterVersion);
    }

    public function stage(): StageCode
    {
        return StageCode::Detail;
    }
}
