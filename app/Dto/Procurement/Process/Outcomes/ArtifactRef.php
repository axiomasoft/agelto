<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Outcomes;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessValue;

final readonly class ArtifactRef
{
    public function __construct(public string $disk, public string $key, public ContentHash $sha256)
    {
        ProcessValue::text($disk);
        ProcessValue::text($key);
    }
}
