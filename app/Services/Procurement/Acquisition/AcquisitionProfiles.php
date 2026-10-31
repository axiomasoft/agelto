<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\AcquisitionLaunch;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Acquisition\Contracts\AcquisitionProfilePreparer;
use App\Services\Procurement\Platform\PlatformRegistry;

final readonly class AcquisitionProfiles
{
    public function __construct(private PlatformRegistry $platforms) {}

    public function preparer(Crawler $profile): AcquisitionProfilePreparer
    {
        $registry = $profile->registry ?? throw new \InvalidArgumentException('Profile registry is missing.');
        $code = $registry->platform?->getAttribute('code');
        if (! is_string($code)) {
            throw new \InvalidArgumentException('Profile platform is missing.');
        }
        $definition = $this->platforms->get(new PlatformCode($code));
        $definition->registries->get($registry->code);
        $descriptor = $definition->acquisitionProfile ?? throw new \InvalidArgumentException('Platform has no acquisition profile capability.');

        $preparer = app($descriptor->preparerClass);
        if (! $preparer instanceof AcquisitionProfilePreparer) {
            throw new \LogicException('Invalid profile preparer binding.');
        }

        return $preparer;
    }

    public function prepare(Crawler $profile, AcquisitionKind $kind, ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $through = null): AcquisitionLaunch
    {
        if (! $profile->is_active) {
            throw new \InvalidArgumentException('Acquisition profile is disabled.');
        }

        return $this->preparer($profile)->prepare($profile, $kind, $from, $through);
    }
}
