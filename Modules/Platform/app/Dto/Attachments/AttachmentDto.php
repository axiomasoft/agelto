<?php

declare(strict_types=1);

namespace Platform\Dto\Attachments;

final class AttachmentDto
{
    public function __construct(
        public string $externalId,
        public string $url,
        public string $name,
    ) {}
}
