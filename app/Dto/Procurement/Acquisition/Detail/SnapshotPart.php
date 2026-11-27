<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Detail;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Platform\Completeness;

final readonly class SnapshotPart
{
    public function __construct(public string $name, public Completeness $completeness, public bool $required, public ContentHash $bodyHash, public \DateTimeImmutable $fetchedAt)
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) || ($required && $completeness === Completeness::NotApplicable)) {
            throw new \InvalidArgumentException('Invalid snapshot section declaration.');
        }
    }
}
