<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Models\Procurement\Platform\Registry;
use Spatie\LaravelData\Data;

/**
 * Резолвит класс типизированного фильтра через nested registry capability exact platform adapter.
 */
final readonly class CrawlerFilterFactory
{
    public function __construct(
        private PlatformAdapterRegistry $adapters,
    ) {}

    /**
     * @return class-string<Data>
     */
    public function for(Registry $registry): string
    {
        return $this->adapters->filterClass(registry: $registry);
    }

    public function scopeMessage(Registry $registry): string
    {
        return $this->adapters->scopeMessage(registry: $registry);
    }
}
