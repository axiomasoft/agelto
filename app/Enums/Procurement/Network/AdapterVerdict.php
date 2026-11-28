<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

enum AdapterVerdict: string
{
    case Accepted = 'accepted';
    case Partial = 'partial';
    case ContractFailure = 'contract_failure';
    case HandlerFault = 'handler_fault';
}
