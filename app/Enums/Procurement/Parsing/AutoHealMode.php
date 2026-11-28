<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Parsing;

/**
 * Режим автоматического восстановления правил парсинга при детекции дрейфа.
 */
enum AutoHealMode: string
{
    case Disabled = 'disabled';
    case Propose = 'propose';
    case AutoPromoteFallback = 'auto_promote_fallback';
}
