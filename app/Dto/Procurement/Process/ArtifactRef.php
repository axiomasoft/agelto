<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

final readonly class ArtifactRef
{
    public function __construct(public string $disk, public string $key, public ContentHash $sha256)
    {
        ProcessValue::text($disk);
        ProcessValue::text($key);
    }
}
