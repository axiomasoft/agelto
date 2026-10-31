<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Dto\Procurement\Acquisition\ListingPage;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;

final readonly class DiagnosticInput
{
    /** @var list<ListingPage> */
    public array $pages;

    /** @param array<array-key, mixed> $pages */
    public function __construct(public HealthBaseline $baseline, array $pages,
        public int $requestsUsed, public ?int $profileId, public \DateTimeImmutable $from,
        public \DateTimeImmutable $to, public NetworkDeferralReason|NetworkBlockedReason|null $unavailable = null)
    {
        if (! array_is_list($pages) || count($pages) > 2 || $requestsUsed < 0 || $to < $from
            || ($profileId !== null && $profileId < 1)) {
            throw new \InvalidArgumentException('Invalid diagnostic evidence.');
        }
        foreach ($pages as $page) {
            if (! $page instanceof ListingPage) {
                throw new \InvalidArgumentException('Invalid diagnostic page.');
            }
        }
        $this->pages = $pages;
    }
}
