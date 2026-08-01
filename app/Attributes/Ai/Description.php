<?php

declare(strict_types=1);

namespace App\Attributes\Ai;

use Attribute;

/** Семантика поля контракта LLM — уходит в `schema.description` (P2.4). Носитель, не докблок. */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Description
{
    public function __construct(public string $text) {}
}
