<?php

declare(strict_types=1);

namespace App\Enums\Llm;

/** Consumer-owned document type; the provider layer only compares its opaque key. */
interface DocumentScope
{
    public function key(): string;
}
