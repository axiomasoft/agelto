<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

/**
 * Fairness cohort work: `platform:<code>` для работы площадки либо `manual`.
 * Платформа не определяет поведение Process: код используется только как ключ cohort/scope.
 */
final readonly class CohortKey
{
    public const string MANUAL = 'manual';

    private const string PLATFORM_PREFIX = 'platform:';

    public static function assert(string $cohort): void
    {
        if ($cohort !== self::MANUAL && preg_match('/^platform:[a-z0-9][a-z0-9_-]{0,62}$/D', $cohort) !== 1) {
            throw new \InvalidArgumentException('Unknown fairness cohort key.');
        }
    }

    public static function platformCode(string $cohort): ?string
    {
        self::assert($cohort);

        return $cohort === self::MANUAL ? null : substr($cohort, strlen(self::PLATFORM_PREFIX));
    }
}
