<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Parsing\Extraction\DriftSignal;

/**
 * Контракт накопления сигналов структурного дрейфа источника при парсинге.
 */
interface DriftCollector
{
    public function record(DriftSignal $signal): void;

    /**
     * @return list<DriftSignal>
     */
    public function signals(): array;

    public function hasCritical(): bool;

    public function clear(): void;
}
