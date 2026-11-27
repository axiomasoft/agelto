<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Control;

use App\Dto\Procurement\Platform\Identity\SourceIdentity;

/** Trusted registry binding, resolved before acquiring subject/work locks. */
final readonly class ProcurementIdentity
{
    public function __construct(public int $platformId, public int $registryId, public SourceIdentity $source)
    {
        if ($platformId < 1 || $registryId < 1) {
            throw new \InvalidArgumentException('Invalid procurement identity binding.');
        }
    }

    public function lockMaterial(): string
    {
        return json_encode(['procurement', $this->platformId, $this->source->type->value, $this->source->value->value],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
