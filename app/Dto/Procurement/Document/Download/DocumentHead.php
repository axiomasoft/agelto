<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Download;

final readonly class DocumentHead
{
    public function __construct(public int $documentId, public int $generation, public ?int $versionId)
    {
        if ($documentId < 1 || $generation < 1 || ($versionId !== null && $versionId < 1)) {
            throw new \InvalidArgumentException('Invalid document head.');
        }
    }
}
