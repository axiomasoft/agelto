<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Platform\Definition\PlatformDefinition;

interface PlatformDefinitionProvider
{
    /** Config-only assembly: no network, database, filesystem writes or adapter construction. */
    public function definition(): PlatformDefinition;
}
