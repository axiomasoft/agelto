<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Governance;

/** Один active intent на subject/stage (partial unique index); несовместимые ждут pending. */
enum IntentState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Terminal = 'terminal';
}
