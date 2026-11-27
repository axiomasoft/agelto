<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Health;

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use Spatie\LaravelData\Data;

final class CheckResult extends Data
{
    /** @param array<string, mixed> $measured */
    public function __construct(
        public PlatformCheckKind $kind,
        public PlatformCheckVerdict $verdict,
        public array $measured,
        public int $sampleSize,
        public int $requestsUsed,
        public string $message,
    ) {}
}
