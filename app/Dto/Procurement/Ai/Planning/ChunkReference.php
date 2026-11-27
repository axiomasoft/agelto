<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Planning;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;

final readonly class ChunkReference
{
    public function __construct(public ProcessUuid $id, public ContentHash $outputHash) {}
}
