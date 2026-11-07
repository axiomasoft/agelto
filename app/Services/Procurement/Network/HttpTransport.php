<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\AuthorizedHttpRequest;
use App\Dto\Procurement\Network\BinaryBodyArtifact;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\HttpResponseHead;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\ReservedProviderNetwork;
use App\Dto\Procurement\Network\ResolvedUrl;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportMetrics;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Network\TransportFailure;
use App\Enums\Telemetry\LogChannel;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Network\ResponseDecodeFailed;
use App\Exceptions\Procurement\Network\ResponseLimitExceeded;
use App\Exceptions\Procurement\Network\TransportInterrupted;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Services\Llm\Execution\PreparedProviderTransport;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Procurement\SourceTlsTrust;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException as ClientRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;

/** Single physical send, no retries/redirects/cookies, no externally mutable PendingRequest. */
final readonly class HttpTransport
{
    public function __construct(private SourceTlsTrust $trust, private ResponseDecoder $decoder) {}

    public function sendOnce(AuthorizedHttpRequest $authorized, ResolvedUrl $resolved, HttpRequestPayload $payload, ExecutionContext|OutboundExecutionContext $context, TransferBudget $budget): HttpAttemptResult
    {
        $this->outsideTransaction();
        $plan = $authorized->plan;
        if ($plan->url->hash()->hex !== $resolved->url->hash()->hex) {
            throw new \LogicException('Resolved URL does not match authorized request.');
        }
        $authorized->consume();
        $wireBytes = 0;
        $code = 0;
        $connectStatus = 0;
        $sink = new BoundedSink($plan->profile->maxEntityBytes, fn () => $context->tick(), $budget);
        $artifactKey = null;
        $keepArtifact = false;
        $head = null;
        $metrics = null;
        try {
            $context->tick();
            $version = curl_version();
            if ($version === false || ! is_int($version['features'])) {
                throw new \LogicException('Cannot inspect pinned cURL features.');
            }
            $encoding = ($version['features'] & CURL_VERSION_LIBZ) !== 0 ? 'gzip, deflate' : 'identity';
            $host = $plan->url->origin->host;
            $port = $plan->url->origin->port;
            $curl = self::pinnedCurlOptions($host, $port, $resolved->address, $plan->profile->lowSpeedSeconds, $authorized->route);
            // A fresh, private Laravel client; facade fakes/global middleware cannot replace this handler.
            try {
                $trustOptions = $this->verifiedTlsOptions($host);
                $handler = new PinnedCurlHandler;
            } catch (\LogicException) {
                return new HttpAttemptResult(null, TransportFailure::Configuration, 0, 0);
            }
            $request = (new PendingRequest)->setClient(new Client(['handler' => $handler]));
            $options = [
                ...self::baseTransportOptions($authorized->route, $plan->profile->connectSeconds, $plan->profile->totalSeconds, $sink, $curl),
                'decode_content' => $encoding, 'headers' => $payload->headers,
                'progress' => function (int $total, int $downloaded) use ($context, $budget, &$wireBytes): void {
                    $context->tick();
                    $delta = max(0, $downloaded - $wireBytes);
                    $wireBytes = max($wireBytes, $downloaded);
                    $budget->wire($delta);
                },
                'on_headers' => static function (ResponseInterface $response) use ($encoding, &$head): void {
                    // Capture before decompression, sink limits or text decoding can fail.
                    $headers = [];
                    foreach ($response->getHeaders() as $name => $values) {
                        if (! is_string($name)) {
                            throw new \LogicException('Expected a response header name.');
                        }
                        $headers[$name] = array_values($values);
                    }
                    $head = new HttpResponseHead($response->getStatusCode(), $headers);
                    $contentEncoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                    if ($contentEncoding !== '' && $contentEncoding !== 'identity' && ! in_array($contentEncoding, explode(', ', $encoding), true)) {
                        throw new ResponseDecodeFailed('Unsupported Content-Encoding.');
                    }
                    if (self::headersByteLength($response) > 65_536) {
                        throw new ResponseLimitExceeded('Response header limit exceeded.');
                    }
                },
                'on_stats' => static function (TransferStats $stats) use (&$code, &$wireBytes, &$connectStatus, &$metrics, $budget, $handler): void {
                    $errorData = $stats->getHandlerErrorData();
                    $code = is_int($errorData) ? $errorData : 0;
                    $connectStatus = $handler->proxyConnectStatus();
                    $metrics = TransportMetrics::fromCurlStats($stats->getHandlerStats());
                    $download = $metrics->values['size_download'];
                    $size = (int) $download;
                    $delta = max(0, $size - $wireBytes);
                    $wireBytes = max($wireBytes, $size);
                    $budget->wire($delta);
                },
                ...$trustOptions,
            ];
            if ($payload->body !== null) {
                $options['body'] = $payload->body;
            }
            $response = $request->send($plan->method, (string) (new Uri($plan->url->reveal()))->withScheme($plan->url->origin->scheme)->withHost($host)->withPort($port), $options);
            $sink->rewind();
            if ($plan->profile->responseKind === ResponseKind::Text) {
                $body = $this->decoder->decode($sink->getContents(), $response->header('Content-Type'), $plan->profile);
            } else {
                try {
                    $artifactKey = 'outbound/staged/'.$plan->physicalRequestId->value;
                    $stream = $sink->detach();
                    if (! is_resource($stream)) {
                        throw new \RuntimeException('Binary spool is unavailable.');
                    }
                    try {
                        $hash = hash_init('sha256');
                        hash_update_stream($hash, $stream);
                        rewind($stream);
                        if (! Storage::disk('local')->writeStream($artifactKey, $stream)) {
                            throw new \RuntimeException('Binary artifact write failed.');
                        }
                        $body = new BinaryBodyArtifact(new ArtifactRef('local', $artifactKey, new ContentHash(hash_final($hash))), $sink->bytes());
                    } finally {
                        fclose($stream);
                    }
                } catch (OutboundOwnershipLost|ExecutionInterrupted $exit) {
                    throw $exit;
                } catch (\Throwable) {
                    return new HttpAttemptResult(null, TransportFailure::Storage, $wireBytes, $sink->bytes(), $code, $head, $metrics);
                }
            }
            $headers = [];
            foreach ($response->toPsrResponse()->getHeaders() as $name => $values) {
                if (! is_string($name)) {
                    throw new \LogicException('Expected a response header name.');
                }
                $headers[$name] = array_values($values);
            }
            $envelope = new ResponseEnvelope($response->status(), $headers, $body, $wireBytes, $sink->bytes());
            $keepArtifact = true;

            return new HttpAttemptResult($envelope, null, $wireBytes, $sink->bytes(), metrics: $metrics);
        } catch (ResponseLimitExceeded) {
            return new HttpAttemptResult(null, TransportFailure::ByteLimit, $wireBytes, $sink->bytes(), $code, $head, $metrics);
        } catch (ResponseDecodeFailed) {
            return new HttpAttemptResult(null, TransportFailure::Decode, $wireBytes, $sink->bytes(), $code, $head, $metrics);
        } catch (OutboundOwnershipLost|ExecutionInterrupted $exit) {
            throw new TransportInterrupted(
                new HttpAttemptResult(null, TransportFailure::Cancelled, $wireBytes, $sink->bytes(), $code, $head, $metrics), $exit,
            );
        } catch (ConnectionException|RequestException|ClientRequestException $error) {
            if ($error instanceof ClientRequestException && $error->response->status() === 407) {
                return new HttpAttemptResult(null, TransportFailure::ProxyAuth, $wireBytes, $sink->bytes(), $code, $head, $metrics);
            }
            $previous = $error;
            do {
                if ($previous instanceof ResponseDecodeFailed || $previous instanceof ResponseLimitExceeded) {
                    return new HttpAttemptResult(null, $previous instanceof ResponseLimitExceeded ? TransportFailure::ByteLimit : TransportFailure::Decode, $wireBytes, $sink->bytes(), $code, $head, $metrics);
                }
                $previous = $previous->getPrevious();
            } while ($previous !== null);
            $failure = match (true) {
                $connectStatus === 407 => TransportFailure::ProxyAuth,
                $code === 7 && $authorized->route->proxy() !== '' => TransportFailure::ProxyConnect,
                default => match ($code) {
                    5 => TransportFailure::ProxyConnect,
                    6, 7 => TransportFailure::Connect,
                    28 => TransportFailure::Timeout,
                    18, 52, 56 => TransportFailure::Truncated,
                    35, 51, 58, 60, 77, 83, 90, 91 => TransportFailure::Tls,
                    61 => TransportFailure::Decode,
                    default => TransportFailure::Connect,
                },
            };

            return new HttpAttemptResult(null, $failure, $wireBytes, $sink->bytes(), $code, $head, $metrics);
        } finally {
            try {
                $sink->close();
            } catch (\Throwable $cleanupError) {
                LogChannel::Network->warning('Outbound sink close failed during transport cleanup.', [
                    'physical_request_id' => $plan->physicalRequestId->value,
                    'error_class' => $cleanupError::class,
                ]);
            }
            if ($artifactKey !== null && ! $keepArtifact) {
                try {
                    if (! Storage::disk('local')->delete($artifactKey)) {
                        LogChannel::Network->warning('Outbound staged artifact delete returned false during transport cleanup.', [
                            'physical_request_id' => $plan->physicalRequestId->value,
                            'artifact_key' => $artifactKey,
                        ]);
                    }
                } catch (\Throwable $cleanupError) {
                    LogChannel::Network->warning('Outbound staged artifact delete failed during transport cleanup.', [
                        'physical_request_id' => $plan->physicalRequestId->value,
                        'artifact_key' => $artifactKey,
                        'error_class' => $cleanupError::class,
                    ]);
                }
            }
        }
    }

    public function prepareProvider(ProviderWireRequest $wire, string $physical, ReservedProviderNetwork $network,
        ExecutionContext $context, string $apiKey, int $latestStartNs): PreparedProviderTransport
    {
        $this->outsideTransaction();
        $plan = $network->permit->ticket->plan;
        $bodyMatches = $wire->method === 'GET'
            ? ($plan->bodyHash === null && $wire->body === '' && $wire->bodyHash === hash('sha256', ''))
            : ($plan->bodyHash?->hex === $wire->bodyHash);
        if ($plan->physicalRequestId->value !== $physical
            || $plan->url->hash()->hex !== $network->resolved->url->hash()->hex
            || (new SafeUrl($wire->endpoint))->hash()->hex !== $plan->url->hash()->hex
            || $plan->method !== $wire->method
            || ! $bodyMatches) {
            throw new \LogicException('Provider wire request does not match reserved network plan.');
        }
        $profile = $plan->profile;
        $host = $plan->url->origin->host;
        $port = $plan->url->origin->port;
        $ip = $network->resolved->address;
        $route = $network->route->binding;
        $budget = new TransferBudget($profile->maxEntityBytes, $profile->maxEntityBytes);
        $sink = new BoundedSink($profile->maxEntityBytes, fn () => $context->tick(), $budget);
        $wireBytes = 0;
        $wireMeasured = false;
        $curlCode = 0;
        /** @var array<string, int|float>|null $curlStats */
        $curlStats = null;
        $curl = self::pinnedCurlOptions($host, $port, $ip, $profile->lowSpeedSeconds, $route);
        $trust = $this->verifiedTlsOptions($host);
        $request = (new PendingRequest)->setClient(new Client(['handler' => new PinnedCurlHandler]))->withOptions([
            ...self::baseTransportOptions($route, $profile->connectSeconds, $profile->totalSeconds, $sink, $curl),
            'decode_content' => false, 'headers' => ['Content-Type' => 'application/json', 'Content-Length' => (string) strlen($wire->body),
                'Expect' => '', 'Accept-Encoding' => 'identity', 'x-goog-api-key' => $apiKey],
            'progress' => function (int $total, int $received) use ($context, $budget, &$wireBytes, &$wireMeasured): void {
                /** @var int $wireBytes */
                $delta = max(0, $received - $wireBytes);
                $wireBytes = max($wireBytes, $received);
                if ($received > 0) {
                    $wireMeasured = true;
                }
                $context->tick();
                $budget->wire($delta);
            },
            'on_headers' => static function (ResponseInterface $response): void {
                if (self::headersByteLength($response) > 65_536 || ! in_array(strtolower($response->getHeaderLine('Content-Encoding')), ['', 'identity'], true)) {
                    throw new \RuntimeException('Unsupported or oversized provider response headers.');
                }
            },
            'on_stats' => static function (TransferStats $stats) use (&$curlCode, &$wireBytes, &$wireMeasured, &$curlStats, $budget): void {
                /** @var int $wireBytes */
                $errorData = $stats->getHandlerErrorData();
                $curlCode = is_int($errorData) && $errorData >= 0 ? $errorData : 0;
                $metrics = TransportMetrics::fromCurlStats($stats->getHandlerStats());
                $size = (int) $metrics->values['size_download'];
                $delta = max(0, $size - $wireBytes);
                $wireBytes = max($wireBytes, $size);
                $values = $metrics->values;
                $values['size_download'] = $wireBytes;
                $curlStats = $values;
                $wireMeasured = true;
                $budget->wire($delta);
            }, ...$trust]);

        return new PreparedProviderTransport($request, $wire, $physical, $latestStartNs, $sink, function () use ($context, $profile): bool {
            $context->tick();

            return $context->canStart(Duration::seconds($profile->totalSeconds));
        }, usesProxy: $route->proxy() !== '', telemetry: static function () use (&$wireBytes, &$wireMeasured, &$curlCode, &$curlStats, $sink): array {
            /**
             * @var int $wireBytes
             * @var bool $wireMeasured
             * @var int $curlCode
             * @var array<string, int|float>|null $curlStats
             */
            $entityBytes = $sink->bytes();
            $observedWireBytes = max($wireBytes, $entityBytes);

            return [
                'wire_bytes' => $observedWireBytes,
                'entity_bytes' => $entityBytes,
                'wire_measured' => $wireMeasured || $observedWireBytes > 0,
                'curl_code' => $curlCode,
                'stats' => $curlStats,
            ];
        });
    }

    /** @return array<int, mixed> */
    private static function pinnedCurlOptions(string $host, int $port, string $address, int $lowSpeedSeconds, HttpRouteBinding $route): array
    {
        $connectHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $ip = str_contains($address, ':') ? '['.$address.']' : $address;
        $curl = [
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => $lowSpeedSeconds,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECT_TO => [$connectHost.':'.$port.':'.$ip.':'.$port],
            CURLOPT_HTTPPROXYTUNNEL => $route->proxy() !== '',
            // CONNECT status is proxy-phase evidence (on_stats), never an origin response head.
            CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
        ];
        if ($route->credentials() !== null) {
            $curl[CURLOPT_PROXYUSERPWD] = $route->credentials();
        }

        return $curl;
    }

    /**
     * @param  array<int, mixed>  $curl
     * @return array<string, mixed>
     */
    private static function baseTransportOptions(HttpRouteBinding $route, int $connectSeconds, int $totalSeconds, BoundedSink $sink, array $curl): array
    {
        return [
            'allow_redirects' => false,
            'http_errors' => false,
            'cookies' => false,
            'stream' => false,
            'proxy' => $route->proxy(),
            'verify' => true,
            'connect_timeout' => $connectSeconds,
            'timeout' => $totalSeconds,
            'sink' => $sink,
            'curl' => $curl,
        ];
    }

    /** @return array<string, mixed> */
    private function verifiedTlsOptions(string $host): array
    {
        $trustOptions = $this->trust->optionsFor($host);
        if (isset($trustOptions['verify']) && ! is_readable($trustOptions['verify'])) {
            throw new \LogicException('Configured TLS trust is unavailable.');
        }

        return $trustOptions;
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Outbound I/O is forbidden inside a database transaction.');
        }
    }

    private static function headersByteLength(ResponseInterface $response): int
    {
        $length = 0;
        foreach ($response->getHeaders() as $name => $values) {
            $length += strlen((string) $name) + array_sum(array_map(strlen(...), $values));
        }

        return $length;
    }
}
