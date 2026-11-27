<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Outcomes;

enum ItemFailureReason: string
{
    case InvalidInput = 'invalid_input';
    case ContractRejected = 'contract_rejected';
}
