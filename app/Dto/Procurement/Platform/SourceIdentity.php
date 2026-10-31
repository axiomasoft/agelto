<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class SourceIdentity
{
    public function __construct(
        public PlatformCode $platform,
        public SourceIdentifierType $type,
        public SourceIdentifierValue $value,
    ) {}

    /** @return array{platform: string, type: string, value: string} */
    public function toArray(): array
    {
        return ['platform' => $this->platform->value, 'type' => $this->type->value, 'value' => $this->value->value];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 3 || array_diff(['platform', 'type', 'value'], array_keys($data)) !== []
            || ! is_string($data['platform']) || ! is_string($data['type']) || ! is_string($data['value'])) {
            throw new InvalidPlatformDefinition('Source identity requires exactly string platform, type and value.');
        }

        return new self(new PlatformCode($data['platform']), new SourceIdentifierType($data['type']), new SourceIdentifierValue($data['value']));
    }
}
