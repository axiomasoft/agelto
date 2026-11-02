<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Services\Llm\Schema\CanonicalJson;

/** Pure immutable wire, without credentials or any authority to send it. */
final readonly class ProviderWireRequest
{
    public string $bodyHash;

    public string $fingerprint;

    public function __construct(
        public ProviderOperationPlan $operation,
        public string $method,
        public string $endpoint,
        public string $allowedOrigin,
        public string $body,
    ) {
        $url = parse_url($endpoint);
        $origin = parse_url($allowedOrigin);
        if ($method !== 'POST' || ! is_array($url) || ! is_array($origin)
            || ! isset($url['scheme'], $url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || array_diff_key($origin, array_flip(['scheme', 'host', 'port'])) !== []
            || $url['scheme'] !== ($origin['scheme'] ?? null) || $url['host'] !== ($origin['host'] ?? null)
            || ($url['port'] ?? null) !== ($origin['port'] ?? null)
            || ! in_array($url['scheme'], ['http', 'https'], true) || strlen($body) > 8_650_752) {
            throw new \InvalidArgumentException('Invalid sealed wire origin/method/body.');
        }
        json_decode($body, flags: JSON_THROW_ON_ERROR);
        $this->bodyHash = hash('sha256', $body);
        $this->fingerprint = hash('sha256', CanonicalJson::encode([
            'logical_key' => $operation->identity->logicalKey, 'context' => $operation->context->json,
            'purpose' => $operation->identity->purpose->value, 'input' => $operation->identity->inputHash,
            'method' => $method, 'endpoint' => $endpoint, 'origin' => $allowedOrigin, 'body' => $this->bodyHash,
        ]));
    }

    public function assertOperation(ProviderOperationPlan $plan): void
    {
        if ($plan != $this->operation) {
            throw new \InvalidArgumentException('Wire does not belong to the pinned operation.');
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['fingerprint' => $this->fingerprint];
    }
}
