<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Services\Procurement\Acquisition\Contracts\AcquisitionProfilePreparer;
use Spatie\LaravelData\Data;

/** Application composition, separate from pure fetch/parser capabilities. */
final readonly class AcquisitionProfileDescriptor
{
    /** @param class-string<Data> $filterClass
     * @param  class-string<AcquisitionProfilePreparer>  $preparerClass
     */
    public function __construct(public string $filterClass, public string $preparerClass,
        public string $scopeMessage)
    {
        if (! is_subclass_of($filterClass, Data::class)
            || ! is_subclass_of($preparerClass, AcquisitionProfilePreparer::class)
            || $scopeMessage === '') {
            throw new InvalidPlatformDefinition('Invalid acquisition profile descriptor.');
        }
    }
}
