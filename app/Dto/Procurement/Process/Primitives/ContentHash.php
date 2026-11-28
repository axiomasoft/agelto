<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Primitives;

final readonly class ContentHash
{
    public function __construct(public string $hex)
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new \InvalidArgumentException('Expected a canonical SHA-256 digest.');
        }
    }

    public static function of(string $bytes): self
    {
        return new self(hash('sha256', $bytes));
    }
}
