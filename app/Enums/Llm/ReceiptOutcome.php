<?php

declare(strict_types=1);

namespace App\Enums\Llm;

enum ReceiptOutcome: string
{
    case Success = 'success';
    case NonbillableCacheMiss = 'known_nonbillable_cache_miss';
    case NonbillableRetry = 'known_retryable_nonbillable';
    case BilledError = 'billed_error';
    case Unknown = 'unknown';
}
