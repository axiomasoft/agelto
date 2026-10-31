<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

final readonly class ExtensionDescriptor
{
    public function __construct(
        public string $dto,
        public string $persister,
        public AdapterVersion $schemaVersion,
        public AdapterVersion $parserVersion,
    ) {}
}
