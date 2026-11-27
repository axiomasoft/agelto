<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Prepare;

final readonly class PdfBatchText
{
    /** @var non-empty-list<PreparePage> */
    public array $pages;

    /** @param array<array-key, PreparePage> $pages */
    public function __construct(public string $anchoredText, array $pages, public float $seconds)
    {
        if ($pages === [] || ! array_is_list($pages) || $seconds <= 0) {
            throw new \InvalidArgumentException('Batch page evidence and duration required.');
        }
        $this->pages = $pages;
    }
}
