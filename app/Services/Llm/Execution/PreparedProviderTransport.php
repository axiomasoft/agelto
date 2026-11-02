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

    public function __construct(PendingRequest $request, private readonly ProviderWireRequest $expected,
        private readonly string $physicalCallId, private readonly int $latestStartNs, private readonly StreamInterface $sink,
        private readonly \Closure $canStart, private readonly bool $usesProxy = false)
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
                return new ProviderTransportOutcome($this->physicalCallId, null, null, ProviderTransportFailure::Deadline);
            }
            $response = $this->request->withBody($wire->body, 'application/json')->send($wire->method, $wire->endpoint);
            $this->sink->rewind();

            $headers = [];
            foreach ($response->toPsrResponse()->getHeaders() as $name => $values) {
                if (! is_string($name)) {
                    throw new \RuntimeException('Invalid provider response header name.');
                }
                $headers[$name] = array_values($values);
            }

            return new ProviderTransportOutcome($this->physicalCallId, $response->status(), $this->sink->getContents(), null, $headers);
        } catch (ConnectionException|TransferException $exception) {
            $previous = $exception;
            $code = 0;
            do {
                if ($previous instanceof RequestException || $previous instanceof ConnectException) {
                    $value = $previous->getHandlerContext()['errno'] ?? 0;
                    $code = is_int($value) ? $value : 0;
                }
                $previous = $previous->getPrevious();
            } while ($previous !== null);
            $failure = match (true) {
                $code === 5 || $code === 7 && $this->usesProxy => ProviderTransportFailure::ProxyConnect,
                $code === 28 => ProviderTransportFailure::Timeout,
                in_array($code, [35, 51, 58, 60, 77, 83, 90, 91], true) => ProviderTransportFailure::Tls,
                in_array($code, [18, 52, 56], true) => ProviderTransportFailure::Truncated,
                default => ProviderTransportFailure::Connection,
            };

            return new ProviderTransportOutcome($this->physicalCallId, null, null, $failure);
        } catch (\RuntimeException) {
            return new ProviderTransportOutcome($this->physicalCallId, null, null, ProviderTransportFailure::Interrupted);
        } finally {
            $this->sink->close();
        }
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
