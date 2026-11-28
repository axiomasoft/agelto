<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Contract;

use Tests\Support\Platforms\Eis\EisContractManifest;
use Tests\Support\Platforms\JsonProcurement\JsonContractManifest;

/** Explicit test composition seam. Production platform validation never loads this registry. */
final readonly class ContractRegistry
{
    /** @return array<string, ContractManifest> */
    public static function manifests(): array
    {
        return ['eis' => EisContractManifest::manifest(), 'json' => JsonContractManifest::manifest()];
    }
}
