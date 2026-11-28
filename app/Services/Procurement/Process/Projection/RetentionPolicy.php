<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Projection;

use Illuminate\Contracts\Config\Repository;

/**
 * Boot-validated policy `process.retention`: сроки хранения ledger history.
 * Transitions и operator audit — минимум 365 дней; меньшие значения отвергаются с путём ключа,
 * а не заменяются default.
 */
final readonly class RetentionPolicy
{
    public const int MINIMUM_DAYS = 365;

    public int $transitionDays;

    public int $auditDays;

    public function __construct(Repository $config)
    {
        $retention = $config->get('process.retention');
        if (! is_array($retention) || array_keys($retention) !== ['transition_days', 'audit_days']) {
            throw new \InvalidArgumentException('Invalid retention policy: process.retention');
        }
        $this->transitionDays = self::days($retention['transition_days'], 'transition_days');
        $this->auditDays = self::days($retention['audit_days'], 'audit_days');
    }

    private static function days(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < self::MINIMUM_DAYS) {
            throw new \InvalidArgumentException('Invalid retention policy: process.retention.'.$key);
        }

        return $value;
    }
}
