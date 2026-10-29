<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

enum HealthSignal: string
{
    case Ignored = 'ignored';
    case Success = 'success';
    case Failure = 'failure';
    case CredentialHold = 'credential_hold';
    case Maintenance = 'maintenance';
}
