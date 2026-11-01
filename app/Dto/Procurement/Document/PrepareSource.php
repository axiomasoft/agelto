<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

final readonly class PrepareSource
{
    public function __construct(public DocumentHead $head, public BlobReference $blob, public string $mime) {}
}
