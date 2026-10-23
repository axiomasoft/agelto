<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Details;

use App\Dto\Organization\OrganizationIdentifierData;
use InvalidArgumentException;

/**
 * Организация из источника до сопоставления с единым реестром организаций.
 */
final readonly class OrganizationData
{
    /** @param list<OrganizationIdentifierData> $identifiers */
    public function __construct(
        public string $fullName,
        public ?string $inn = null,
        public ?string $kpp = null,
        public ?string $ogrn = null,
        public ?string $addressRaw = null,
        public ?string $phone = null,
        public ?string $email = null,
        public array $identifiers = [],
    ) {
        $values = [];
        foreach ($identifiers as $identifier) {
            $previous = $values[$identifier->platformCode][$identifier->type] ?? null;
            if ($previous !== null && $previous !== $identifier->value) {
                throw new InvalidArgumentException('Один тип идентификатора площадки содержит противоречивые значения.');
            }
            $values[$identifier->platformCode][$identifier->type] = $identifier->value;
        }
    }
}
