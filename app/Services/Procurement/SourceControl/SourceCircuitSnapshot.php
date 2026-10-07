<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Enums\Procurement\Crawler\SourceCircuitState;
use Carbon\CarbonImmutable;

/**
 * Неизменяемый снимок строки `prc_source_circuits` для чистой логики `SourceCircuitPolicy`.
 */
final readonly class SourceCircuitSnapshot
{
    public function __construct(
        public SourceCircuitState $state = SourceCircuitState::Closed,
        public bool $enabled = true,
        public bool $manuallyPaused = false,
        public int $failureThreshold = 5,
        public int $retryIntervalMinutes = 10,
        public int $consecutiveFailures = 0,
        public int $openCount = 0,
        public ?CarbonImmutable $openedAt = null,
        public ?CarbonImmutable $retryAt = null,
        public ?ListingFailureKind $lastFailureKind = null,
        public ?CarbonImmutable $lastFailureAt = null,
    ) {}

    public function with(
        ?SourceCircuitState $state = null,
        ?bool $manuallyPaused = null,
        ?int $consecutiveFailures = null,
        ?int $openCount = null,
        ?CarbonImmutable $openedAt = null,
        ?CarbonImmutable $retryAt = null,
        bool $clearRetryAt = false,
        ?ListingFailureKind $lastFailureKind = null,
        ?CarbonImmutable $lastFailureAt = null,
    ): self {
        return new self(
            state: $state ?? $this->state,
            enabled: $this->enabled,
            manuallyPaused: $manuallyPaused ?? $this->manuallyPaused,
            failureThreshold: $this->failureThreshold,
            retryIntervalMinutes: $this->retryIntervalMinutes,
            consecutiveFailures: $consecutiveFailures ?? $this->consecutiveFailures,
            openCount: $openCount ?? $this->openCount,
            openedAt: $openedAt ?? $this->openedAt,
            retryAt: $clearRetryAt ? null : ($retryAt ?? $this->retryAt),
            lastFailureKind: $lastFailureKind ?? $this->lastFailureKind,
            lastFailureAt: $lastFailureAt ?? $this->lastFailureAt,
        );
    }
}
