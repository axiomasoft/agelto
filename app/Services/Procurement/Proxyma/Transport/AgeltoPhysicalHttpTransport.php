<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Transport;

use Closure;
use LogicException;
use Proxyma\Application\Execution\ResponseDecoder;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\HttpResponseHead;
use Proxyma\Domain\Execution\RawResponseBody;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\TransportMetrics;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Http\TransportDoctor;

/** Согласованное исключение Q104_correction_ownership=b: pinned transport с download metadata. */
final readonly class AgeltoPhysicalHttpTransport implements PhysicalTransport
{
    // ext-curl не экспортирует CURLE_LOGIN_DENIED во всех поддержанных сборках; ABI код стабилен.
    private const int CurlLoginDenied = 67;

    public function __construct(private ConnectionScope $scope, private WireSealer $sealer,
        private int $maxResponseBytes = 67_108_864, private ?Closure $cancelled = null)
    {
        if ($maxResponseBytes < 1 || $maxResponseBytes > 256 * 1024 * 1024) {
            throw new LogicException('Physical response limit must be between 1 byte and 256 MiB.');
        }
    }

    public function sendOnce(AuthorizedWireRequest $request): AttemptResult
    {
        $this->scope->assertOutsideTransaction();
        $wire = $request->prepared ?? throw new LogicException('Physical transport requires a sealed wire.');
        $this->sealer->verify($wire);
        if ($request->wire !== $wire->request || ! $request->snapshot->fullFingerprint->equals($wire->request->fingerprint())) {
            throw new LogicException('Capability does not match its prepared wire.');
        }
        $this->verifyTrust($wire->tls);
        if ($wire->proxyTls !== null) {
            $this->verifyTrust($wire->proxyTls);
        }
        $options = $this->options($wire);
        $curl = curl_init();
        if ($curl === false) {
            throw new LogicException('Unable to initialize physical cURL handle.');
        }
        $multi = curl_multi_init();
        $state = new CurlTransferState($wire->request->responseProfile, $this->scope, $this->maxResponseBytes);
        $options[CURLOPT_WRITEFUNCTION] = static fn (\CurlHandle $handle, string $chunk): int => $state->writeChunk($chunk);
        $options[CURLOPT_HEADERFUNCTION] = static fn (\CurlHandle $handle, string $line): int => $state->writeHeader($line);
        $options[CURLOPT_XFERINFOFUNCTION] = static fn (\CurlHandle $handle, int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded): int => $state->recordProgress($downloaded, $uploaded);
        $added = false;
        try {
            if (! curl_setopt_array($curl, $options)) {
                throw new LogicException('Unable to bind sealed cURL options.');
            }
            $cancelled = $this->cancelled !== null && ($this->cancelled)();
            $this->scope->assertOutsideTransaction();
            $request->consume();
            if ($cancelled) {
                return AttemptResult::failed(TransportFailure::Cancelled);
            }
            curl_multi_add_handle($multi, $curl);
            $added = true;
            $this->runMultiLoop($multi, $curl, $wire, $state);

            return $this->buildResponse($multi, $curl, $wire, $state);
        } finally {
            $state->reader?->close();
            if ($added) {
                curl_multi_remove_handle($multi, $curl);
            }
            curl_multi_close($multi);
            curl_close($curl);
        }
    }

    private function runMultiLoop(\CurlMultiHandle $multi, \CurlHandle $curl, SealedWire $wire, CurlTransferState $state): void
    {
        do {
            $code = curl_multi_exec($multi, $running);
            if ($code !== CURLM_OK) {
                $state->failure = TransportFailure::Protocol;
                break;
            }
            if (! $running) {
                break;
            }
            if (($this->cancelled) !== null && ($this->cancelled)()) {
                $state->failure = TransportFailure::Cancelled;
                break;
            }
            if ((float) curl_getinfo($curl, CURLINFO_CONNECT_TIME) > 0
                && hrtime(true) - $state->lastActivity > $wire->request->timeouts->readTimeoutMs * 1_000_000) {
                $state->failure = TransportFailure::Timeout;
                break;
            }
            if (curl_multi_select($multi, 0.01) === -1) {
                usleep(1000);
            }
        } while ($running);
    }

    private function classifyFailure(CurlTransferState $state, SealedWire $wire, ?HttpResponseHead $head, int $error, int $proxyStatus): ?TransportFailure
    {
        return $state->failure ?? match (true) {
            $state->limit => TransportFailure::ByteLimit,
            $state->readerError !== null => TransportFailure::Protocol,
            $wire->proxy !== null && ($state->status === 407 || $proxyStatus === 407 || $error === self::CurlLoginDenied) => TransportFailure::ProxyAuth,
            in_array($error, [CURLE_SSL_CACERT, CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_PINNEDPUBKEYNOTMATCH], true) => TransportFailure::Tls,
            $error === CURLE_OPERATION_TIMEDOUT => TransportFailure::Timeout,
            $error === CURLE_PARTIAL_FILE || ($error !== 0 && $state->status > 0) => TransportFailure::Truncated,
            $error !== 0 && $wire->proxy !== null => TransportFailure::ProxyConnect,
            $error !== 0 => TransportFailure::Connect,
            $head === null => TransportFailure::Protocol,
            default => null,
        };
    }

    private function buildResponse(\CurlMultiHandle $multi, \CurlHandle $curl, SealedWire $wire, CurlTransferState $state): AttemptResult
    {
        $info = curl_multi_info_read($multi);
        $error = $info !== false && is_int($info['result']) ? $info['result'] : curl_errno($curl);
        $stats = curl_getinfo($curl);
        $metrics = new TransportMetrics(...array_map(static fn (string $key): int => max(0, (int) round((is_numeric($stats[$key] ?? null) ? (float) $stats[$key] : 0.0) * 1000)),
            ['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'total_time']));
        $head = $state->status >= 100 && $state->status <= 599 ? new HttpResponseHead($state->status, $state->headers) : null;
        $proxyStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CONNECTCODE);
        $failure = $this->classifyFailure($state, $wire, $head, $error, $proxyStatus);
        $bytes = max($state->reader?->wireBytes() ?? 0, $stats === false ? 0 : (int) $stats['size_download']);
        if ($failure !== null) {
            return AttemptResult::failed($failure, $bytes, $state->reader?->decodedBytes() ?? 0, $error, $head, $metrics);
        }

        try {
            $reader = $state->ensureReader();
            $body = $reader->finish();
            if ($wire->request->responseProfile->kind === 'text' && $body instanceof RawResponseBody) {
                $body = (new ResponseDecoder)->decode($body->bytes(), $state->headers['content-type'][0] ?? null, $wire->request->responseProfile);
            }

            return AttemptResult::delivered(new ResponseDescriptor($state->status, $state->headers, $body, $bytes, $reader->decodedBytes()), $metrics);
        } catch (\LengthException) {
            return AttemptResult::failed(TransportFailure::ByteLimit, $bytes, 0, $error, $head, $metrics);
        } catch (\Throwable) {
            return AttemptResult::failed(TransportFailure::Protocol, $bytes, 0, $error, $head, $metrics);
        }
    }

    private function verifyTrust(TlsTrustConfiguration $trust): void
    {
        if ($trust->pinnedCertificateSha256 !== null) {
            throw new LogicException('Certificate pins require a handshake verifier; unsupported pins fail closed.');
        }
        if ($trust->caBundlePath !== null && ($trust->caBundleSha256 === null || ! is_readable($trust->caBundlePath)
            || ! hash_equals($trust->caBundleSha256->hex, (string) @hash_file('sha256', $trust->caBundlePath)))) {
            throw new LogicException('Sealed TLS CA bundle changed or is unavailable.');
        }
    }

    private static function host(string $host): string
    {
        return str_contains($host, ':') ? '['.$host.']' : $host;
    }

    /** @return array<int, mixed> */
    private function options(SealedWire $wire): array
    {
        $origin = $wire->destination;
        $host = self::host($origin->originalHost);
        $ip = self::host($origin->address);
        $port = $origin->port;
        $headers = ['Host: '.$host.(in_array([$origin->url->origin->scheme, $port], [['http', 80], ['https', 443]], true) ? '' : ':'.$port), 'Expect:', 'Accept:', 'Content-Type:'];
        foreach ($wire->request->headers->reveal() as $name => $value) {
            $headers[] = $name.($value === '' ? ';' : ': '.$value);
        }
        $options = [
            CURLOPT_URL => $wire->request->url->reveal(), CURLOPT_CUSTOMREQUEST => $wire->request->method,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_FAILONERROR => false,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_DNS_CACHE_TIMEOUT => 0,
            CURLOPT_CONNECT_TO => ["{$host}:{$port}:{$ip}:{$port}"],
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_CONNECTTIMEOUT_MS => $wire->request->timeouts->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $wire->request->timeouts->totalTimeoutMs,
            CURLOPT_NOSIGNAL => true, CURLOPT_NOPROGRESS => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SUPPRESS_CONNECT_HEADERS => true, CURLOPT_HTTP_CONTENT_DECODING => false,
        ];
        if ($wire->request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($wire->request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $wire->request->body->bytes();
        }
        if ($wire->tls->caBundlePath !== null) {
            $options[CURLOPT_CAINFO] = $wire->tls->caBundlePath;
        }
        if ($wire->route->kind === RouteKind::Proxy) {
            $proxy = $wire->proxy ?? throw new LogicException('Proxy dispatch requires a sealed locally resolved endpoint.');
            $endpoint = $proxy->endpoint;
            TransportDoctor::assertProtocol($endpoint->protocol);
            $proxyHost = self::host($endpoint->host);
            $options[CURLOPT_PROXY] = $endpoint->protocol->value.'://'.$proxyHost.':'.$endpoint->port;
            $options[CURLOPT_PROXYTYPE] = match ($endpoint->protocol) {
                ProxyProtocol::Http => CURLPROXY_HTTP,
                ProxyProtocol::Https => CURLPROXY_HTTPS,
                ProxyProtocol::Socks5 => CURLPROXY_SOCKS5,
                ProxyProtocol::Socks5h => throw new LogicException('Remote DNS is forbidden.'),
            };
            $options[CURLOPT_RESOLVE][] = $proxyHost.':'.$endpoint->port.':'.self::host($proxy->address);
            $options[CURLOPT_HTTPPROXYTUNNEL] = $origin->url->origin->scheme === 'https';
            $options[CURLOPT_PROXY_SSL_VERIFYPEER] = true;
            $options[CURLOPT_PROXY_SSL_VERIFYHOST] = 2;
            if ($wire->proxyTls?->caBundlePath !== null) {
                $options[CURLOPT_PROXY_CAINFO] = $wire->proxyTls->caBundlePath;
            }
            if ($wire->credential !== null) {
                $options[CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
                $options[CURLOPT_PROXYUSERNAME] = $wire->credential->username ?? '';
                $options[CURLOPT_PROXYPASSWORD] = $wire->credential->revealSecret();
            }
            if ($origin->url->origin->scheme === 'http' && $endpoint->protocol !== ProxyProtocol::Socks5) {
                // Forwarding с исходным hostname позволил бы proxy повторить DNS мимо policy.
                $parts = parse_url($wire->request->url->reveal());
                $options[CURLOPT_URL] = 'http://'.$ip.':'.$port.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        return $options;
    }
}
