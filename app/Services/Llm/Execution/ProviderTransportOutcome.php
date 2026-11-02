<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Enums\Llm\ProviderTransportFailure;

/** Raw bounded receipt is financial evidence, never safe telemetry or publication authority. */
final readonly class ProviderTransportOutcome
{
    /** @param array<string, list<string>> $headers */
    public function __construct(public string $physicalCallId, public ?int $status, private ?string $body,
        public ?ProviderTransportFailure $failure, public array $headers = [])
    {
        if (($status === null) !== ($body === null) || ($status === null) === ($failure === null)) {
            throw new \InvalidArgumentException('Transport has exactly one typed outcome.');
        }
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return ['physical_call_id' => $this->physicalCallId, 'status' => $this->status, 'failure' => $this->failure?->value];
    }
}
