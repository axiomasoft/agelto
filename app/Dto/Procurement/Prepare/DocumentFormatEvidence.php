<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare;

use App\Enums\Procurement\Document\DocumentFormat;
use App\Enums\Procurement\Document\UnprocessableReason;

final readonly class DocumentFormatEvidence
{
    public function __construct(
        public ?DocumentFormat $format,
        public string $extension,
        public string $detectedMime,
        public ?string $strategy,
        public ?UnprocessableReason $reason,
        public ?string $detail,
    ) {}
}
