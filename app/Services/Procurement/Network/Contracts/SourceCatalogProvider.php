<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network\Contracts;

use App\Dto\Procurement\Network\Control\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;

/**
 * Typed source targets для explicit catalog sync. Реализация собирает definitions только
 * из config, без DB/network I/O. Runtime registry принимает здесь provider catalogs;
 * catalogs площадок поступают через PlatformDefinition.
 */
interface SourceCatalogProvider
{
    public function sourceTargets(): SourceTargetDefinitions|ProviderTargetDefinitions;
}
