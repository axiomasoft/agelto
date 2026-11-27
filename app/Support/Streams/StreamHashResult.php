<?php

declare(strict_types=1);

namespace App\Support\Streams;

final readonly class StreamHashResult
{
    public function __construct(
        public string $sha256,
        public int $bytes,
    ) {}
}
