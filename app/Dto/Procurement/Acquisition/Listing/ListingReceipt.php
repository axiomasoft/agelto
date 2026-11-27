<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummary;
use App\Dto\Procurement\Platform\Identity\CollectionGuard;
use App\Dto\Procurement\Process\Primitives\ContentHash;

/** Indexed by crawl block: bounded page membership, never embedded in work checkpoint JSON. */
final readonly class ListingReceipt
{
    /**
     * @param  list<ContentHash>  $observations
     * @param  list<ListingDetailReference>  $details
     */
    public function __construct(public ContentHash $identities, public array $observations, public string $outcome, public ?string $reason = null,
        public bool $uninterrupted = true, public array $details = [])
    {
        CollectionGuard::check($observations, ContentHash::class, 1000);
        CollectionGuard::check($details, ListingDetailReference::class, 1000);
        if (! in_array($outcome, ['page', 'pass', 'split', 'observed', 'complete', 'incomplete'], true)
            || (($outcome === 'incomplete') !== ($reason !== null))) {
            throw new \InvalidArgumentException('Invalid listing receipt.');
        }
    }

    /** @param list<ListingDetailReference> $details */
    public static function page(ListingPage $page, string $outcome, ?string $reason = null, bool $uninterrupted = true, array $details = []): self
    {
        return new self($page->summaries->digest(false), array_map(static fn (ProcurementSummary $s): ContentHash => ContentHash::of(json_encode([$s->identity->toArray(), $s->sourceRevision, $s->fingerprint->hex], JSON_THROW_ON_ERROR)), $page->summaries->items), $outcome, $reason, $uninterrupted, $details);
    }

    public function json(): string
    {
        return json_encode(['identities' => $this->identities->hex, 'observations' => array_map(static fn (ContentHash $h): string => $h->hex, $this->observations),
            'outcome' => $this->outcome, 'reason' => $this->reason, 'uninterrupted' => $this->uninterrupted,
            'details' => array_map(static fn (ListingDetailReference $d): string => $d->json(), $this->details)], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $v = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($v) || count($v) !== 6 || ! is_array($v['details'] ?? null) || ! array_is_list($v['details']) || count($v['details']) > 1000
            || ! is_bool($v['uninterrupted'] ?? null) || ! is_string($v['identities'] ?? null) || ! is_array($v['observations'] ?? null)
            || ! array_is_list($v['observations']) || ! is_string($v['outcome'] ?? null) || ! array_key_exists('reason', $v)
            || ($v['reason'] !== null && ! is_string($v['reason']))) {
            throw new \InvalidArgumentException('Invalid listing receipt schema.');
        }
        $hashes = [];
        foreach ($v['observations'] as $hash) {
            if (! is_string($hash)) {
                throw new \InvalidArgumentException('Invalid observation hash.');
            }
            $hashes[] = new ContentHash($hash);
        }

        $details = [];
        foreach ($v['details'] as $detail) {
            if (! is_string($detail)) {
                throw new \InvalidArgumentException('Invalid detail reference.');
            }
            $details[] = ListingDetailReference::fromJson($detail);
        }

        return new self(new ContentHash($v['identities']), $hashes, $v['outcome'], $v['reason'], $v['uninterrupted'], $details);
    }
}
