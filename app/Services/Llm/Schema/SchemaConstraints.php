<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use Attribute;

/** Trusted PHP contract metadata; never accepted from model output. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class SchemaConstraints
{
    /** @param array<string, int|string|bool> $keywords */
    public function __construct(public array $keywords) {}
}
