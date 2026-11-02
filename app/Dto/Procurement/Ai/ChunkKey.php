<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;

/** Exactly the database unique tuple; physical retries cannot change this identity. */
final readonly class ChunkKey
{
    public function __construct(public ProcessUuid $runId, public string $windowKey, public ContentHash $recipeHash, public ContentHash $subrangeKey, public int $outputOrdinal)
    {
        if ($windowKey === '' || $outputOrdinal < 0) {
            throw new \InvalidArgumentException('Invalid chunk key.');
        }
    }
}
