<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;

final readonly class ChunkReference
{
    public function __construct(public ProcessUuid $id, public ContentHash $outputHash) {}
}
