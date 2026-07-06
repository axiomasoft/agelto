<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

use App\Dto\Procurement\ListingBatch;
use App\Dto\Procurement\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Contracts\SourceMethod;
use OutOfBoundsException;

/**
 * Управляемый `SourceMethod` для `FreshRunProcessorTest` — выдаёт заранее заданную
 * последовательность `ListingBatch` по одному на каждый вызов `collectListing()`.
 * Если тест запрашивает больше блоков, чем положил в очередь — бросает исключение
 * (тест обязан спланировать ровно нужное число итераций, включая hard-cap сценарии).
 */
final class QueueSourceMethod implements SourceMethod
{
    private int $cursorIndex = 0;

    /**
     * @param  list<ListingBatch>  $batches
     */
    public function __construct(
        private readonly array $batches,
        private readonly bool $available = true,
    ) {}

    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        if (! array_key_exists($this->cursorIndex, $this->batches)) {
            throw new OutOfBoundsException('QueueSourceMethod: очередь блоков исчерпана раньше, чем ожидал тест.');
        }

        return $this->batches[$this->cursorIndex++];
    }

    public function collectDetails(string $sourceNumber, Registry $registry): ProcurementDetails
    {
        return new ProcurementDetails;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}
