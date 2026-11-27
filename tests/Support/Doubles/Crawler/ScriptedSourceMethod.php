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
use Throwable;

/**
 * Сценарный источник для тестов надёжности: заранее заданные шаги листинга и карточек.
 * Шаг — ответ (`ListingBatch` / `ProcurementDetails`) или исключение (нет прокси, сеть, баг).
 * Запоминает курсоры запросов, чтобы тест проверял, какой блок запрашивался и сколько раз.
 */
final class ScriptedSourceMethod implements SourceMethod
{
    /** @var list<int> `blockFrom` каждого запроса листинга */
    public array $requestedBlocks = [];

    public int $detailCalls = 0;

    /**
     * @param  list<ListingBatch|Throwable>  $listing
     * @param  list<ProcurementDetails|Throwable>  $details
     */
    public function __construct(
        private array $listing = [],
        private array $details = [],
    ) {}

    /** @param list<ListingBatch|Throwable> $steps */
    public function thenListing(array $steps): self
    {
        array_push($this->listing, ...$steps);

        return $this;
    }

    /** @param list<ProcurementDetails|Throwable> $steps */
    public function thenDetails(array $steps): self
    {
        array_push($this->details, ...$steps);

        return $this;
    }

    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        $this->requestedBlocks[] = $cursor->blockFrom;
        $step = array_shift($this->listing) ?? throw new OutOfBoundsException('ScriptedSourceMethod: шаги листинга кончились.');

        return $step instanceof Throwable ? throw $step : $step;
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
    {
        $this->detailCalls++;
        $step = array_shift($this->details) ?? throw new OutOfBoundsException('ScriptedSourceMethod: шаги карточек кончились.');

        return $step instanceof Throwable ? throw $step : $step;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
