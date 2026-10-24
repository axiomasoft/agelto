<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Services\Procurement\Process\ProcessPayloadCodec;

/** Canonical v1 identity contains only the stage-specific semantic identity, never a random run id. */
final readonly class WorkIdentity
{
    public function __construct(public WorkInput $input) {}

    public function key(): ContentHash
    {
        $payload = (new ProcessPayloadCodec)->encodeInput($this->input);

        return ContentHash::of('work-identity:v1:'.$payload->json);
    }
}
