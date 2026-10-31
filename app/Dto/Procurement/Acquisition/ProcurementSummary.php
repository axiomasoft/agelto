<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\Process\ContentHash;

final readonly class ProcurementSummary
{
    public function __construct(
        public SourceIdentity $identity,
        public string $title,
        public string $sourceStage,
        public \DateTimeImmutable $publishedAt,
        public \DateTimeImmutable $updatedAt,
        public ContentHash $fingerprint,
        public ?string $sourceRevision = null,
        public ?string $initialPrice = null,
        public ?string $currency = null,
        public ?CursorEnvelope $detailLocator = null,
        public bool $updatedAtDayPrecision = false,
        public ?ListingFacts $facts = null,
        public ?\DateTimeImmutable $updatedBefore = null,
    ) {
        if ($initialPrice !== null && ! preg_match('/^\\d{1,15}(?:\\.\\d{1,2})?$/D', $initialPrice)) {
            throw new \InvalidArgumentException('Invalid decimal price.');
        }
        if ($updatedAtDayPrecision !== ($updatedBefore !== null) || ($updatedBefore !== null && $updatedBefore <= $updatedAt)) {
            throw new \InvalidArgumentException('Source-day precision requires its exclusive upper bound.');
        }
    }
}
