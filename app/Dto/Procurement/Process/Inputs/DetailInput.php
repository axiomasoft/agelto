<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Inputs;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\StageCode;

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
