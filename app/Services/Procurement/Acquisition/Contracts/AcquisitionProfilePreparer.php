<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Contracts;

use App\Dto\Procurement\Acquisition\Control\AcquisitionLaunch;
use App\Dto\Procurement\Acquisition\Listing\ListingPlan;
use App\Dto\Procurement\Acquisition\Listing\ListingTask;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;

interface AcquisitionProfilePreparer
{
    /** Reference reads and normalization occur before allocation locks. No HTTP or process writes. */
    public function prepare(Crawler $profile, AcquisitionKind $kind, ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $through = null): AcquisitionLaunch;

    /** Recover the immutable assignment rather than rereading mutable profile filters. */
    public function recoveryPlan(ListingTask $task): ListingPlan;
}
