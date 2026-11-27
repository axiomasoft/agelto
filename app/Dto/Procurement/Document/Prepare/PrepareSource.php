<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Prepare;

use App\Dto\Procurement\Document\Download\DocumentHead;
use App\Dto\Procurement\Document\Storage\BlobReference;

final readonly class PrepareSource
{
    public function __construct(public DocumentHead $head, public BlobReference $blob, public string $mime) {}
}
