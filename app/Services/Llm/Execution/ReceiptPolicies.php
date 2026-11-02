<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use Illuminate\Container\Attributes\Singleton;

/** Fail closed until the owning provider package explicitly registers its response policy. */
#[Singleton]
final class ReceiptPolicies
{
    /** @var array<string, ReceiptPolicy> */
    private array $policies = [];

    public function register(string $provider, ReceiptPolicy $policy): void
    {
        $this->policies[$provider] = $policy;
    }

    public function assess(string $provider, OperationPurpose $purpose, int $status, string $body): ReceiptAssessment
    {
        return isset($this->policies[$provider])
            ? $this->policies[$provider]->assess($purpose, $status, $body)
            : new ReceiptAssessment(ReceiptOutcome::Unknown, null, 'unregistered_response_policy');
    }
}
