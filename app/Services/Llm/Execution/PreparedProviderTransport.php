<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Enums\Llm\ProviderTransportFailure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\StreamInterface;

/** One committed grant, one private fixed Laravel client, one physical send. Never queue it. */
final class PreparedProviderTransport
{
    private bool $consumed = false;

    private readonly PendingRequest $request;

    /**
     * @param  (\Closure(): bool)  $canStart
     * @param  (\Closure(): array{wire_bytes: int, entity_bytes: int, wire_measured: bool, curl_code: int, stats: array<string, int|float>|null})|null  $telemetry
     */
    public function __construct(PendingRequest $request, private readonly ProviderWireRequest $expected,
        private readonly string $physicalCallId, private readonly int $latestStartNs, private readonly StreamInterface $sink,
        private readonly \Closure $canStart, private readonly bool $usesProxy = false,
        private readonly ?\Closure $telemetry = null)
    {
        $this->request = clone $request;
    }

    public function sendOnce(ProviderWireRequest $wire): ProviderTransportOutcome
    {
        if ($this->consumed || $wire->fingerprint !== $this->expected->fingerprint || DB::transactionLevel() !== 0) {
            throw new \LogicException('Transport capability consumed, mismatched or inside a transaction.');
        }
        $this->consumed = true;
        try {
            if (hrtime(true) > $this->latestStartNs || ! ($this->canStart)()) {
                return new ProviderTransportOutcome($this->physicalCallId, null, null, ProviderTransportFailure::Deadline, [], 0, 0, false, 0, null);
            }
            $response = $this->request->withHeaders($wire->headers)->withBody($wire->body, $wire->contentType)->send($wire->method, $wire->endpoint);
            $telemetry = $this->snapshotTelemetry();
            $this->sink->rewind();
            $body = $this->sink->getContents();

            $headers = [];
            foreach ($response->toPsrResponse()->getHeaders() as $name => $values) {
                if (! is_string($name)) {
                    throw new \RuntimeException('Invalid provider response header name.');
                }
                $headers[$name] = array_values($values);
            }

            return new ProviderTransportOutcome($this->physicalCallId, $response->status(), $body, null, $headers,
                $telemetry['wire_bytes'], $telemetry['entity_bytes'], $telemetry['wire_measured'],
                $telemetry['curl_code'], $telemetry['stats']);
        } catch (ConnectionException|TransferException $exception) {
            $telemetry = $this->snapshotTelemetry();
            $previous = $exception;
            $code = 0;
            $interrupted = false;
            $truncated = false;
            do {
                if ($previous instanceof RequestException || $previous instanceof ConnectException) {
                    $value = $previous->getHandlerContext()['errno'] ?? 0;
                    $code = is_int($value) ? $value : 0;
                } elseif ($previous instanceof \RuntimeException) {
                    $truncated = $truncated || str_contains($previous->getMessage(), 'limit') || str_contains($previous->getMessage(), 'headers');
                    $interrupted = ! $truncated;
                }
                $previous = $previous->getPrevious();
            } while ($previous !== null);
            $curlCode = $code > 0 ? $code : $telemetry['curl_code'];
            $failure = match (true) {
                $interrupted || $curlCode === 42 => ProviderTransportFailure::Interrupted,
                $truncated || in_array($curlCode, [18, 23, 52, 56, 63], true) => ProviderTransportFailure::Truncated,
                $curlCode === 5 || $curlCode === 7 && $this->usesProxy => ProviderTransportFailure::ProxyConnect,
                $curlCode === 28 => ProviderTransportFailure::Timeout,
                in_array($curlCode, [35, 51, 58, 60, 77, 83, 90, 91], true) => ProviderTransportFailure::Tls,
                default => ProviderTransportFailure::Connection,
            };

            return new ProviderTransportOutcome($this->physicalCallId, null, null, $failure, [],
                $telemetry['wire_bytes'], $telemetry['entity_bytes'], $telemetry['wire_measured'],
                $curlCode, $telemetry['stats']);
        } catch (\RuntimeException $exception) {
            $telemetry = $this->snapshotTelemetry();
            $failure = str_contains($exception->getMessage(), 'limit') || str_contains($exception->getMessage(), 'headers')
                ? ProviderTransportFailure::Truncated
                : ProviderTransportFailure::Interrupted;

            return new ProviderTransportOutcome($this->physicalCallId, null, null, $failure, [],
                $telemetry['wire_bytes'], $telemetry['entity_bytes'], $telemetry['wire_measured'],
                $telemetry['curl_code'], $telemetry['stats']);
        } finally {
            $this->sink->close();
        }
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
    private function snapshotTelemetry(): array
    {
        if ($this->telemetry !== null) {
            return ($this->telemetry)();
        }
        $entityBytes = max(0, (int) ($this->sink->getSize() ?? 0));

        return [
            'wire_bytes' => $entityBytes,
            'entity_bytes' => $entityBytes,
            'wire_measured' => false,
            'curl_code' => 0,
            'stats' => null,
        ];
    }

    /** @return array<never, never> */
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
