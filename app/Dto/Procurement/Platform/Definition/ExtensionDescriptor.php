<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Definition;

use App\Dto\Procurement\Platform\Identity\AdapterVersion;

final readonly class ExtensionDescriptor
{
    public function __construct(
        public string $dto,
        public string $persister,
        public AdapterVersion $schemaVersion,
        public AdapterVersion $parserVersion,
    ) {}
}
