<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network\Contracts;

use App\Dto\Procurement\Acquisition\AcquisitionLaunch;
use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Platform\DiagnosticInput;
use App\Dto\Procurement\Platform\ProbePlan;
use App\Enums\Procurement\Health\PlatformCheckKind;

/** Pure adapter preparation and assessment; the common runner owns all effects. */
interface DiagnosticCheck
{
    public function kind(): PlatformCheckKind;

    public function plan(AcquisitionLaunch $launch): ProbePlan;

    public function run(DiagnosticInput $input): CheckResult;
}
