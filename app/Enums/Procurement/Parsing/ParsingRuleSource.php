<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Parsing;

/**
 * Происхождение версии набора правил парсинга.
 */
enum ParsingRuleSource: string
{
    case Seed = 'seed';
    case Manual = 'manual';
    case Import = 'import';
    case AutoHeal = 'auto_heal';
    case Rollback = 'rollback';
}
