<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Enums\Llm\OperationPurpose;

interface ReceiptPolicy
{
    /** Only provider-specific durable evidence can prove nonbilling; a status alone cannot. */
    public function assess(OperationPurpose $purpose, int $httpStatus, string $body): ReceiptAssessment;
}
