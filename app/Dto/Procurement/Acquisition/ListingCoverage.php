<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

final readonly class ListingCoverage
{
    /** @param list<ListingTask> $debt */
    public function __construct(public ?string $verifiedThrough, public ?string $observedThrough, public array $debt) {}

    /** A long outage starts at durable coverage, never at today minus a fixed lookback. */
    public function resumeFrom(string $initialFrom, int $overlapDays = 2): string
    {
        if ($overlapDays < 0 || $overlapDays > 30) {
            throw new \InvalidArgumentException('Invalid coverage overlap.');
        }
        if ($this->verifiedThrough === null) {
            return $initialFrom;
        }

        return max($initialFrom, (new \DateTimeImmutable($this->verifiedThrough))->modify('-'.$overlapDays.' days')->format('Y-m-d'));
    }
}
