<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

/** Verified outside locks; a previously catalogued object may not be resurrected after GC. */
final readonly class VerifiedStoredArtifact
{
    public function __construct(public BlobReference $blob, public bool $catalogued) {}
}
