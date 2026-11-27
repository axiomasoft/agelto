<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Health;

use Spatie\LaravelData\Data;

final class HealthBaseline extends Data
{
    /** @param array<string, array<string, mixed>> $cardTabs
     *  @param list<string> $requiredHeaders
     *  @param array<string, array<string, mixed>> $thresholds */
    public function __construct(
        public string $platform,
        public string $capturedAt,
        public string $source,
        public array $cardTabs,
        public array $requiredHeaders,
        public array $thresholds,
        public bool $calibrated,
        public string $sha256,
    ) {}
}
