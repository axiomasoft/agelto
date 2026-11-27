<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

/** Platform metadata; never a canonical lookup key. */
final readonly class EisDetailExtension
{
    public function __construct(
        public ?int $ikzYear = null, public ?string $ikzCustomerCode = null, public ?string $ikzPlanNumber = null,
        public ?string $ikzPlangraphNumber = null, public ?string $ikzOkpdClass = null, public ?string $ikzOkpdSubclass = null,
        public ?string $ikzOkpdGroup = null, public ?string $ikzKvr = null, public ?string $etpName = null, public ?string $etpUrl = null,
        public ?string $contactPersonName = null, public ?string $contactPersonEmail = null,
        public ?string $ikz = null, public ?string $etpNumber = null, public ?string $registryGuid = null,
        public bool $hasCommonInfo = false,
    ) {}

}
