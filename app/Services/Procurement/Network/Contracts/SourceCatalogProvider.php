<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network\Contracts;

use App\Dto\Procurement\Platform\SourceTargetDefinitions;

/**
 * Source targets одной площадки для explicit catalog sync. Реализация собирает typed definitions
 * только из config: никакого DB/network I/O (boot и DI безопасны). P2.9–P2.12 подключает сюда
 * PlatformDefinition; до этого регистрируются только test fixtures.
 */
interface SourceCatalogProvider
{
    public function sourceTargets(): SourceTargetDefinitions;
}
