<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

use LogicException;

/**
 * Единственный FK-backed scope правила сопоставления сырого статуса.
 */
enum StatusMappingApplicability: string
{
    case Platform = 'platform';
    case Registry = 'registry';

    public static function fromScopeIds(?int $platformId, ?int $registryId): self
    {
        return match ([
            $platformId !== null,
            $registryId !== null,
        ]) {
            [true, false] => self::Platform,
            [false, true] => self::Registry,
            default => throw new LogicException('Status mapping must have exactly one applicability scope.'),
        };
    }
}
