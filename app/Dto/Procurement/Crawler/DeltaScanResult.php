<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Enums\Procurement\Crawler\DeltaScanOutcome;
use App\Models\Procurement\Crawler\Run;

final readonly class DeltaScanResult
{
    private function __construct(
        public DeltaScanOutcome $outcome,
        public ?Run $run,
    ) {}

    public static function processed(Run $run): self
    {
        return new self(outcome: DeltaScanOutcome::Processed, run: $run);
    }

    public static function busy(): self
    {
        return new self(outcome: DeltaScanOutcome::Busy, run: null);
    }

    public static function missingArchiveProfile(): self
    {
        return new self(outcome: DeltaScanOutcome::MissingArchiveProfile, run: null);
    }
}
