<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Enums\Llm\ProviderTransportFailure;
use Proxyma\Domain\Execution\AttemptResult;

/** Raw bounded receipt is financial evidence, never safe telemetry or publication authority. */
final readonly class ProviderTransportOutcome
{
    /**
     * @param  array<string, list<string>>  $headers
     * @param  array<string, int|float>|null  $stats
     */
    public function __construct(
        public string $physicalCallId,
        public ?int $status,
        private ?string $body,
        public ?ProviderTransportFailure $failure,
        public array $headers = [],
        public int $wireBytes = 0,
        public int $entityBytes = 0,
        public bool $wireMeasured = false,
        public int $curlCode = 0,
        public ?array $stats = null,
        public ?AttemptResult $networkResult = null,
    ) {
        if (($status === null) !== ($body === null)
            || ($status === null) === ($failure === null)
            || min($wireBytes, $entityBytes) < 0
            || $curlCode < 0) {
            throw new \InvalidArgumentException('Transport has exactly one typed outcome.');
        }
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /** @return array<string, bool|int|string|null> */
    public function __debugInfo(): array
    {
        return [
            'physical_call_id' => $this->physicalCallId,
            'status' => $this->status,
            'failure' => $this->failure?->value,
            'wire_bytes' => $this->wireBytes,
            'entity_bytes' => $this->entityBytes,
            'wire_measured' => $this->wireMeasured,
        ];
    }
}
