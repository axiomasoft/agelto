<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Diagnostics;

use App\Dto\Procurement\Process\Primitives\ContentHash;

/** Serialized adapter boundary. Core never decodes payload or normalizes opaque strings. */
final readonly class CursorEnvelope
{
    public ContentHash $payloadHash;

    public function __construct(public string $codecId, public int $schemaVersion, public string $serializedPayload)
    {
        if (! preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $codecId) || $schemaVersion < 1 || strlen($serializedPayload) > 16384) {
            throw new \InvalidArgumentException('Invalid cursor envelope.');
        }
        $this->payloadHash = new ContentHash(hash('sha256', $serializedPayload));
    }
}
