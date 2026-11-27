<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Contract;

use App\Dto\Procurement\Platform\Definition\PlatformDefinition;

final readonly class ContractManifest
{
    /** @param list<ContractCase> $cases
     * @param  list<string>  $additionalRequired  Platform-owned semantic checks, registered alongside the corpus.
     */
    public function __construct(public PlatformDefinition $definition, public array $cases, public array $additionalRequired = []) {}
}
