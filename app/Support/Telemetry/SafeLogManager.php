<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;

class SafeLogManager extends LogManager
{
    protected function createEmergencyLogger(): Logger
    {
        /** @var Logger $logger */
        $logger = parent::createEmergencyLogger();

        return $this->tap(name: 'emergency', logger: $logger);
    }
}
