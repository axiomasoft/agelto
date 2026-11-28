<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Platform\Definition\AcquisitionProfileDescriptor;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Platform\PlatformRegistry;
use Spatie\LaravelData\Data;

/**
 * Резолвит класс типизированного фильтра через nested registry capability exact platform adapter.
 */
final readonly class CrawlerFilterFactory
{
    public function __construct(
        private PlatformRegistry $adapters,
    ) {}

    /**
     * @return class-string<Data>
     */
    public function for(Registry $registry): string
    {
        return $this->descriptor($registry)->filterClass;
    }

    public function scopeMessage(Registry $registry): string
    {
        return $this->descriptor($registry)->scopeMessage;
    }

    private function descriptor(Registry $registry): AcquisitionProfileDescriptor
    {
        $code = $registry->platform?->getAttribute('code');
        if (! is_string($code)) {
            throw new \InvalidArgumentException('Profile platform is missing.');
        }
        $definition = $this->adapters->get(new PlatformCode($code));
        $definition->registries->get($registry->code);

        return $definition->acquisitionProfile ?? throw new \InvalidArgumentException('Platform does not support acquisition profiles.');
    }
}
