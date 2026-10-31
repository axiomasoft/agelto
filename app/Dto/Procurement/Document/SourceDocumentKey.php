<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

/** Adapter-owned, versioned identity; access URLs never participate. */
final readonly class SourceDocumentKey
{
    public function __construct(public string $value, public int $version)
    {
        if ($value === '' || strlen($value) > 1024 || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8') || $version < 1) {
            throw new \InvalidArgumentException('Invalid source document key.');
        }
    }
}
