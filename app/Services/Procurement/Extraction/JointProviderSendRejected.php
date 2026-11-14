<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Services\Llm\Execution\SendClaimResult;
use Proxyma\Domain\Admission\DemandWait;

/** Forces rollback of both halves before the owner waits or returns denial. */
final class JointProviderSendRejected extends \RuntimeException
{
    public function __construct(public readonly SendClaimResult|DemandWait $result)
    {
        parent::__construct('Joint provider/network claim denied.');
    }
}
