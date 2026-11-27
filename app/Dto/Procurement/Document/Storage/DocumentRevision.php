<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Storage;

use App\Dto\Procurement\Document\Download\DocumentHead;

final readonly class DocumentRevision
{
    public function __construct(public int $id, public int $revisionNo, public DocumentHead $head, public BlobReference $blob) {}
}
