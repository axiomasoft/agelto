<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Platform\SourceIdentity;

final readonly class DocumentReference
{
    public function __construct(public SourceIdentity $procurement, public string $externalId, public string $title, public SafeUrl $url, public SourceDocumentKey $sourceKey, public ?\DateTimeImmutable $expiresAt = null)
    {
        if ($externalId === '' || strlen($externalId) > 512 || $title === '') {
            throw new \InvalidArgumentException('Invalid document reference.');
        }
    }
}
