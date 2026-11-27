<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
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

    /** @var array<string, array<int, ListingBatch>> */
    private array $windows = [];

    /** @var array<string, bool> */
    private array $completeWindows = [];

    /**
     * @param  list<ListingBatch>  $batches
     */
    public function __construct(
        private readonly array $batches,
        private readonly bool $available = true,
        private readonly bool $repeatStableWindows = false,
    ) {}

    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        $window = $cursor->day.':'.$cursor->dateField.':'.json_encode($cursor->priceStack);
        if ($this->repeatStableWindows && $cursor->day !== null && ($this->completeWindows[$window] ?? false)) {
            return $this->windows[$window][$cursor->blockFrom] ?? throw new OutOfBoundsException('Unexpected offset in a stable window replay.');
        }
        if (! array_key_exists($this->cursorIndex, $this->batches)) {
            throw new OutOfBoundsException('QueueSourceMethod: очередь блоков исчерпана раньше, чем ожидал тест.');
        }

        $batch = $this->batches[$this->cursorIndex++];
        if ($this->repeatStableWindows && $cursor->day !== null && $batch->httpStatus === 200) {
            $this->windows[$window][$cursor->blockFrom] = $batch;
            if ($batch->rowsTotal < config('crawler.platforms.eis.block_size')) {
                $this->completeWindows[$window] = true;
            }
        }

        return $batch;
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
    {
        return new ProcurementDetails;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}
