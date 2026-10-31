<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

use App\Dto\Procurement\Details\CommonInfoData;

/** Paired with ExtensionPersister<EisDetailExtension> at the acquisition cutover; no writer active here. */
final readonly class EisDetailExtension
{
    public function __construct(
        public ?int $ikzYear, public ?string $ikzCustomerCode, public ?string $ikzPlanNumber,
        public ?string $ikzPlangraphNumber, public ?string $ikzOkpdClass, public ?string $ikzOkpdSubclass,
        public ?string $ikzOkpdGroup, public ?string $ikzKvr, public ?string $etpName, public ?string $etpUrl,
        public ?string $contactPersonName, public ?string $contactPersonEmail,
        public ?string $ikz = null, public ?string $etpNumber = null, public ?string $registryGuid = null,
    ) {}

    public static function fromCommon(CommonInfoData $data): self
    {
        return new self($data->ikzYear, $data->ikzCustomerCode, $data->ikzPlanNumber, $data->ikzPlangraphNumber,
            $data->ikzOkpdClass, $data->ikzOkpdSubclass, $data->ikzOkpdGroup, $data->ikzKvr,
            $data->etpName, $data->etpUrl, $data->contactPersonName, $data->contactPersonEmail);
    }
}
