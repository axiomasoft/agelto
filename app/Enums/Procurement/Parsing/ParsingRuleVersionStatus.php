<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Parsing;

/**
 * Жизненный цикл версии набора правил парсинга.
 */
enum ParsingRuleVersionStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Active = 'active';
    case Superseded = 'superseded';
    case Rejected = 'rejected';
}
