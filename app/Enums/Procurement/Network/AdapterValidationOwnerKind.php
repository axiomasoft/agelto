<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

enum AdapterValidationOwnerKind: string
{
    case WorkAttempt = 'work_attempt';
    case OutboundOperation = 'outbound_operation';
}
