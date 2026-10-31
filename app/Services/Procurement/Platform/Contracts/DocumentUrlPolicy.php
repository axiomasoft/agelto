<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

/** Pure descriptor validation; physical sends still use the network URL/DNS policy. */
interface DocumentUrlPolicy
{
    public function assertDocumentUrl(string $url): void;
}
