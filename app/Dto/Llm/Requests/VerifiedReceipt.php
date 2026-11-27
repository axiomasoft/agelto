<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

/** Constructed after immutable object and exact operation identity verification outside DB locks. */
final readonly class VerifiedReceipt
{
    public function __construct(public ProviderCall $call, public string $hash, public int $status, public string $body, public ReceiptAssessment $assessment) {}
}
