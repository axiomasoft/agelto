<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Platform;

enum TerminalProof: string
{
    case None = 'none';
    case SemanticEmpty = 'semantic_empty';
    case ShortPage = 'short_page';
    case ExplicitEnd = 'explicit_end';
}
