<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Details;

use App\Enums\Procurement\DetailSnapshotStatus;

/**
 * Parsed common-info and parties projections with independent completeness gates.
 *
 * @param  list<PartyData>  $parties
 */
final readonly class CommonInfoSnapshot
{
    /** @param list<PartyData> $parties */
    public function __construct(
        public DetailSnapshotStatus $status,
        public CommonInfoData $commonInfo,
        public array $parties = [],
        public bool $partiesComplete = false,
    ) {}

    /** @param list<PartyData> $parties */
    public function withParties(array $parties, bool $partiesComplete): self
    {
        return new self(
            status: $this->status,
            commonInfo: $this->commonInfo,
            parties: $parties,
            partiesComplete: $partiesComplete,
        );
    }

    public function authorizesCommonInfoReconciliation(): bool
    {
        return $this->status === DetailSnapshotStatus::Complete;
    }

    public function authorizesPartiesReconciliation(): bool
    {
        return $this->partiesComplete;
    }
}
