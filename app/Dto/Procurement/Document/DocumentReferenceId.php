<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

final readonly class DocumentReferenceId
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new \InvalidArgumentException('Invalid reference ID.');
        }
    }
}
