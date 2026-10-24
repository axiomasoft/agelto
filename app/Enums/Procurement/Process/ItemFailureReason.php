<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

enum ItemFailureReason: string
{
    case InvalidInput = 'invalid_input';
    case ContractRejected = 'contract_rejected';
}
