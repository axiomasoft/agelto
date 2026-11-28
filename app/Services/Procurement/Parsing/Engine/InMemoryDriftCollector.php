<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Engine;

use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Services\Procurement\Platform\Contracts\DriftCollector;

/**
 * Буфер накопления сигналов структурного дрейфа в рамках текущего запуска парсинга.
 */
final class InMemoryDriftCollector implements DriftCollector
{
    /** @var list<DriftSignal> */
    private array $signals = [];

    public function record(DriftSignal $signal): void
    {
        $this->signals[] = $signal;
    }

    /**
     * @return list<DriftSignal>
     */
    public function signals(): array
    {
        return $this->signals;
    }

    public function hasCritical(): bool
    {
        return array_any(
            $this->signals,
            static fn (DriftSignal $signal): bool => $signal->severity === 'critical',
        );
    }

    public function clear(): void
    {
        $this->signals = [];
    }
}
