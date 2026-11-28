<?php

declare(strict_types=1);

namespace App\Dto\Organization;

use InvalidArgumentException;

/** An opaque identifier whose meaning and format belong to the source adapter. */
final readonly class OrganizationIdentifierData
{
    public function __construct(
        public string $platformCode,
        public string $type,
        public string $value,
    ) {
        foreach (['platformCode' => 64, 'type' => 64, 'value' => 255] as $field => $limit) {
            if (trim($this->{$field}) === '' || mb_strlen($this->{$field}) > $limit) {
                throw new InvalidArgumentException("Некорректное поле {$field} внешнего идентификатора организации.");
            }
        }
    }
}
