<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Prepare;

use App\Dto\Procurement\Document\Storage\VerifiedArtifact;

final readonly class PreparedBatch
{
    /** @var non-empty-list<PreparePage> */
    public array $pages;

    /** @param array<array-key, PreparePage> $pages */
    public function __construct(public VerifiedArtifact $artifact, array $pages)
    {
        if ($pages === [] || ! array_is_list($pages)) {
            throw new \InvalidArgumentException('Batch pages required.');
        }
        $this->pages = $pages;
        $first = $pages[0]->page;
        foreach ($pages as $i => $page) {
            if ($page->page !== $first + $i) {
                throw new \InvalidArgumentException('Batch anchors must be contiguous.');
            }
        }
    }
}
