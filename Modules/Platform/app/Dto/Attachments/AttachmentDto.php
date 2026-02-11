<?php

declare(strict_types=1);

namespace Platform\Dto\Attachments;

use Spatie\LaravelData\Data;

final class AttachmentDto extends Data
{
    public function __construct(
        public string $externalId,
        public string $url,
        public string $name,
    ) {}
}
