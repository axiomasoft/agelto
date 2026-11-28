<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Enums\Llm\ProviderTransportFailure;
use App\Support\Time\Monotonic;
use Illuminate\Support\Facades\DB;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\RawResponseBody;
use Proxyma\Domain\Execution\TransportFailure;

/** One committed grant, one private fixed Laravel client, one physical send. Never queue it. */
final class PreparedProviderTransport
{
    private bool $consumed = false;

    private readonly AuthorizedWireRequest $request;

    /**
     * @param  (\Closure(): bool)  $canStart
     */
    public function __construct(AuthorizedWireRequest $request, private readonly ProviderWireRequest $expected,
        private readonly string $physicalCallId, private readonly int $latestStartNs, private readonly PhysicalTransport $sink,
        private readonly \Closure $canStart)
    {
        if ($request->wire->physicalAttemptId->value !== $physicalCallId
            || $request->wire->method !== $expected->method || $request->wire->url->reveal() !== $expected->endpoint
            || ($request->wire->body?->hash->hex ?? hash('sha256', '')) !== $expected->bodyHash
            || ($request->wire->headers->reveal()['content-type'] ?? null) !== $expected->contentType) {
            throw new \LogicException('Provider wire does not match the package capability.');
        }
        $this->request = $request;
    }

    public function sendOnce(ProviderWireRequest $wire): ProviderTransportOutcome
    {
        if ($this->consumed || $wire->fingerprint !== $this->expected->fingerprint || DB::transactionLevel() !== 0) {
            throw new \LogicException('Transport capability consumed, mismatched or inside a transaction.');
        }
        $this->consumed = true;

        return $this->sendPackage();
    }

    private function sendPackage(): ProviderTransportOutcome
    {
        if (Monotonic::nanoseconds() >= $this->latestStartNs || ! ($this->canStart)()) {
            return new ProviderTransportOutcome($this->physicalCallId, null, null, ProviderTransportFailure::Deadline,
                networkResult: AttemptResult::failed(TransportFailure::Cancelled));
        }
        // PhysicalTransport consumes the sealed capability and checks latestStart immediately before dispatch.
        $result = $this->sink->sendOnce($this->request);
        $response = $result->response;
        $body = $response?->body;
        if ($body !== null && ! $body instanceof RawResponseBody) {
            throw new \LogicException('Provider transport requires a bounded raw response.');
        }
        $failure = match ($result->failure) {
            null => null,
            TransportFailure::Connect => ProviderTransportFailure::Connection,
            TransportFailure::ProxyConnect, TransportFailure::ProxyAuth => ProviderTransportFailure::ProxyConnect,
            TransportFailure::Tls => ProviderTransportFailure::Tls,
            TransportFailure::Timeout => ProviderTransportFailure::Timeout,
            TransportFailure::Truncated, TransportFailure::ByteLimit => ProviderTransportFailure::Truncated,
            default => ProviderTransportFailure::Interrupted,
        };

        $metrics = $result->metrics;
        $stats = $metrics === null ? null : [
            'namelookup_time' => $metrics->dnsLookupMs / 1000,
            'connect_time' => $metrics->connectMs / 1000,
            'appconnect_time' => $metrics->tlsHandshakeMs / 1000,
            'starttransfer_time' => $metrics->firstByteMs / 1000,
            'total_time' => $metrics->totalMs / 1000,
            'http_code' => $response === null ? 0 : $response->statusCode,
            'size_download' => $result->wireBytes,
        ];

        return new ProviderTransportOutcome($this->physicalCallId, $response?->statusCode, $body?->bytes(), $failure,
            $response?->head->headers ?? [], $result->wireBytes, $result->decodedBytes, true, $result->curlErrorCode, $stats,
            networkResult: $result);
    }

    /**
     * @return array{
     *     wire_bytes: int,
     *     entity_bytes: int,
     *     wire_measured: bool,
     *     curl_code: int,
     *     stats: array<string, int|float>|null
     * }
     */
    public function __serialize(): array
    {
        throw new \LogicException('Runtime transport capability cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new \LogicException('Runtime transport capability cannot be cloned.');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['physical_call_id' => $this->physicalCallId];
    }
}
