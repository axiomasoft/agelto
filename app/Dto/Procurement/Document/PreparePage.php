<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Enums\Procurement\Document\PageTextStatus;

final readonly class PreparePage
{
    public function __construct(public int $page, public PageTextStatus $status, public ?string $evidence = null)
    {
        if ($page < 1 || ($status === PageTextStatus::ProvedBlank && ($evidence === null || $evidence === ''))) {
            throw new \InvalidArgumentException('Original page anchor and blank proof required.');
        }
    }
}
