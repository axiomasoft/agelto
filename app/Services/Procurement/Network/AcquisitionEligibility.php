<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Repositories\Procurement\Network\NetworkEligibilityRepository;
use App\Repositories\Procurement\Process\Authority\ControlRepository;

/** Advisory dispatch projection. Only the locked network send gate authorizes an effect. */
final readonly class AcquisitionEligibility
{
    public function __construct(private ControlRepository $controls, private NetworkEligibilityRepository $network) {}

    /** @return list<int> */
    public function excludedPlatforms(StageCode $stage): array
    {
        $excluded = [];
        foreach ($this->network->platforms() as $platformId => $code) {
            $keys = ['system', 'acquisition', 'acquisition:platform:'.$code];
            $modes = $this->controls->modes($keys);
            if (count($modes) !== count($keys)
                || in_array(ControlMode::Paused, $modes, true)
                || ! $this->network->hasAvailableTarget($platformId, $stage)) {
                $excluded[] = $platformId;
            }
        }

        return $excluded;
    }
}
