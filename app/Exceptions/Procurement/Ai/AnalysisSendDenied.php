<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Ai;

use App\Services\Llm\Execution\SendClaimResult;

/** The gate denied an operation whose original reservation has been settled as known not sent. */
final class AnalysisSendDenied extends \RuntimeException
{
    public function __construct(public readonly SendClaimResult $claim)
    {
        if ($claim->transport !== null || $claim->denial === null) {
            throw new \InvalidArgumentException('A denied provider send is required.');
        }
        parent::__construct('Provider send denied before the wire.');
    }
}
