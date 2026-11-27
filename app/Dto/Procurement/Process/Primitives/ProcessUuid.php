<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Primitives;

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

    public static function derived(string $seed): self
    {
        $h = hash('sha256', 'listing:v1:'.$seed);

        return new self(substr($h, 0, 8).'-'.substr($h, 8, 4).'-5'.substr($h, 13, 3).'-a'.substr($h, 17, 3).'-'.substr($h, 20, 12));
    }
}
