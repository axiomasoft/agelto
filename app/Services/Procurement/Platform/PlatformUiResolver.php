<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform;

use App\Contracts\Procurement\Platform\PlatformUiExtension;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use Illuminate\Contracts\Container\Container;

/** Application UI seam. Fetch/process paths never resolve UI components. */
final readonly class PlatformUiResolver
{
    public function __construct(private PlatformRegistry $registry, private Container $container) {}

    public function resolve(PlatformCode $code): ?PlatformUiExtension
    {
        $class = $this->registry->get($code)->uiExtension;
        if ($class === null) {
            return null;
        }
        $extension = $this->container->make($class);
        if (! $extension instanceof PlatformUiExtension) {
            throw new InvalidPlatformDefinition('platforms.definitions.'.$code->value.'.uiExtension: incompatible container binding.');
        }

        return $extension;
    }
}
