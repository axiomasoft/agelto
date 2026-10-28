<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\AuthorizedHttpRequest;
use App\Dto\Procurement\Network\BinaryBodyArtifact;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\ResolvedUrl;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Network\TransportFailure;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Network\ResponseDecodeFailed;
use App\Exceptions\Procurement\Network\ResponseLimitExceeded;
use App\Exceptions\Procurement\Network\TransportInterrupted;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
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
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Outbound I/O is forbidden inside a database transaction.');
        }
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
        try {
            $context->tick();
            $version = curl_version();
            if ($version === false || ! is_int($version['features'])) {
                throw new \LogicException('Cannot inspect pinned cURL features.');
            }
            $encoding = ($version['features'] & CURL_VERSION_LIBZ) !== 0 ? 'gzip, deflate' : 'identity';
            $host = $plan->url->origin->host;
            $port = $plan->url->origin->port;
            $connectHost = str_contains($host, ':') ? '['.$host.']' : $host;
            $ip = str_contains($resolved->address, ':') ? '['.$resolved->address.']' : $resolved->address;
            $curl = [
                CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => $plan->profile->lowSpeedSeconds,
                CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECT_TO => [$connectHost.':'.$port.':'.$ip.':'.$port],
                CURLOPT_HTTPPROXYTUNNEL => $authorized->route->proxy() !== '',
            ];
            if ($authorized->route->credentials() !== null) {
                $curl[CURLOPT_PROXYUSERPWD] = $authorized->route->credentials();
            }
            // A fresh, private Laravel client; facade fakes/global middleware cannot replace this handler.
            try {
                $trustOptions = $this->trust->optionsFor($host);
                if (isset($trustOptions['verify']) && ! is_readable($trustOptions['verify'])) {
                    throw new \LogicException('Configured TLS trust is unavailable.');
                }
                $handler = new PinnedCurlHandler;
            } catch (\LogicException) {
                return new HttpAttemptResult(null, TransportFailure::Configuration, 0, 0);
            }
            $request = (new PendingRequest)->setClient(new Client(['handler' => $handler]));
            $options = [
                'allow_redirects' => false, 'http_errors' => false, 'cookies' => false, 'stream' => false,
                'proxy' => $authorized->route->proxy(), 'verify' => true,
                'connect_timeout' => $plan->profile->connectSeconds, 'timeout' => $plan->profile->totalSeconds,
                'decode_content' => $encoding, 'headers' => $payload->headers, 'sink' => $sink, 'curl' => $curl,
                'progress' => function (int $total, int $downloaded) use ($context, $budget, &$wireBytes): void {
                    $context->tick();
                    $delta = max(0, $downloaded - $wireBytes);
                    $wireBytes = max($wireBytes, $downloaded);
                    $budget->wire($delta);
                },
                'on_headers' => static function (ResponseInterface $response) use ($encoding): void {
                    $contentEncoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                    if ($contentEncoding !== '' && $contentEncoding !== 'identity' && ! in_array($contentEncoding, explode(', ', $encoding), true)) {
                        throw new ResponseDecodeFailed('Unsupported Content-Encoding.');
                    }
                    $length = 0;
                    foreach ($response->getHeaders() as $name => $values) {
                        $length += strlen($name) + array_sum(array_map(strlen(...), $values));
                    }
                    if ($length > 65_536) {
                        throw new ResponseLimitExceeded('Response header limit exceeded.');
                    }
                },
                'on_stats' => static function (TransferStats $stats) use (&$code, &$wireBytes, &$connectStatus, $budget): void {
                    $errorData = $stats->getHandlerErrorData();
                    $code = is_int($errorData) ? $errorData : 0;
                    $connect = $stats->getHandlerStats()['http_connectcode'] ?? 0;
                    $connectStatus = is_int($connect) ? $connect : 0;
                    $download = $stats->getHandlerStats()['size_download'] ?? 0;
                    $size = is_int($download) || is_float($download) ? (int) $download : 0;
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
                    return new HttpAttemptResult(null, TransportFailure::Storage, $wireBytes, $sink->bytes(), $code);
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

            return new HttpAttemptResult($envelope, null, $wireBytes, $sink->bytes());
        } catch (ResponseLimitExceeded) {
            return new HttpAttemptResult(null, TransportFailure::ByteLimit, $wireBytes, $sink->bytes(), $code);
        } catch (ResponseDecodeFailed) {
            return new HttpAttemptResult(null, TransportFailure::Decode, $wireBytes, $sink->bytes(), $code);
        } catch (OutboundOwnershipLost|ExecutionInterrupted $exit) {
            throw new TransportInterrupted(
                new HttpAttemptResult(null, TransportFailure::Cancelled, $wireBytes, $sink->bytes(), $code), $exit,
            );
        } catch (ConnectionException|RequestException|ClientRequestException $error) {
            if ($error instanceof ClientRequestException && $error->response->status() === 407) {
                return new HttpAttemptResult(null, TransportFailure::ProxyAuth, $wireBytes, $sink->bytes(), $code);
            }
            $previous = $error;
            do {
                if ($previous instanceof ResponseDecodeFailed || $previous instanceof ResponseLimitExceeded) {
                    return new HttpAttemptResult(null, $previous instanceof ResponseLimitExceeded ? TransportFailure::ByteLimit : TransportFailure::Decode, $wireBytes, $sink->bytes(), $code);
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

            return new HttpAttemptResult(null, $failure, $wireBytes, $sink->bytes(), $code);
        } finally {
            $sink->close();
            if ($artifactKey !== null && ! $keepArtifact) {
                Storage::disk('local')->delete($artifactKey);
            }
        }
    }
}
