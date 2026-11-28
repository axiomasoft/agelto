<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Parsing;

/**
 * Тип инициатора события в журнале правил парсинга.
 */
enum ParsingRuleActorType: string
{
    case User = 'user';
    case System = 'system';
    case Cli = 'cli';
    case Seeder = 'seeder';
    case AutoHeal = 'auto_heal';
}
