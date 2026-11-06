<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

final readonly class UnreferencedBlobCandidate
{
    public function __construct(public BlobReference $blob, public string $verifiedAt) {}
}
