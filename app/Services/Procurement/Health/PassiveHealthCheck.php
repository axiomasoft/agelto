<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health;

use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Platform\Platform;
use Carbon\CarbonInterface;

interface PassiveHealthCheck
{
    public function kind(): PlatformCheckKind;

    public function run(Platform $platform, HealthBaseline $baseline, CarbonInterface $from, CarbonInterface $to): CheckResult;
}
