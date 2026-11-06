<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use Illuminate\Support\Str;

final readonly class ProcessUuid
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Expected a canonical UUID.');
        }
    }

    public static function random(): self
    {
        return new self(Str::lower((string) Str::uuid()));
    }
}
