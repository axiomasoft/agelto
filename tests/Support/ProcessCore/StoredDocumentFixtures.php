<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use Tests\Support\ProcessCore\Http\PermitFixtures;

/** Format/import tests perform real object I/O, so they own committed rows rather than RefreshDatabase's outer transaction. */
final class StoredDocumentFixtures
{
    public static function reset(): void
    {
        PermitFixtures::clean();
        ExecutionFixtures::truncateLedger();
        DocumentStorageFixtures::reset();
    }
}
