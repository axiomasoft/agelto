<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Extraction;

use App\Enums\Procurement\Process\Core\StageCode;
use InvalidArgumentException;

/**
 * Сигнал структурного дрейфа источника, зафиксированный при выполнении правил парсинга.
 */
final readonly class DriftSignal
{
    public function __construct(
        public string $signalCode,
        public StageCode $stage,
        public string $fieldOrSelector,
        public ?string $expected = null,
        public ?string $actual = null,
        public string $severity = 'warning',
    ) {
        if ($severity !== 'warning' && $severity !== 'critical') {
            throw new InvalidArgumentException('DriftSignal severity must be warning or critical.');
        }
    }
}
