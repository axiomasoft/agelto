<?php

declare(strict_types=1);

namespace App\Dto\Crawler\Details;

/**
 * Организация, упомянутая на вкладке `common-info` (`PartiesParser::parse()`), до entity
 * resolution в единый `org_organizations` (это делает `OrganizationResolver`).
 */
final readonly class OrganizationData
{
    public function __construct(
        public string $fullName,
        public ?string $inn = null,
        public ?string $kpp = null,
        public ?string $ogrn = null,
        public ?string $addressRaw = null,
        public ?string $phone = null,
        public ?string $email = null,
    ) {}
}
