<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Screening;

use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use InvalidArgumentException;

final readonly class ScreeningDecision
{
    public function __construct(
        public ScreeningDisposition $disposition,
        public ScreeningDecisionReason $reason,
        public bool $extractionDispatched,
    ) {}

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        $disposition = $value['disposition'] ?? null;
        $reason = $value['reason'] ?? null;
        $extractionDispatched = $value['extraction_dispatched'] ?? null;

        if (! is_string($disposition) || ! is_string($reason) || ! is_bool($extractionDispatched)) {
            throw new InvalidArgumentException('Stored screening decision has an invalid shape.');
        }

        $typedDisposition = ScreeningDisposition::tryFrom($disposition);
        $typedReason = ScreeningDecisionReason::tryFrom($reason);

        if ($typedDisposition === null || $typedReason === null) {
            throw new InvalidArgumentException('Stored screening decision contains an unknown disposition or reason.');
        }

        return new self(
            disposition: $typedDisposition,
            reason: $typedReason,
            extractionDispatched: $extractionDispatched,
        );
    }

    /**
     * @return array{disposition: string, reason: string, extraction_dispatched: bool}
     */
    public function toArray(): array
    {
        return [
            'disposition' => $this->disposition->value,
            'reason' => $this->reason->value,
            'extraction_dispatched' => $this->extractionDispatched,
        ];
    }
}
