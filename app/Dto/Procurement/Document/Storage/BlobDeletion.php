<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Storage;

final readonly class BlobDeletion
{
    public function __construct(public BlobReference $blob, public int $generation) {}
}
