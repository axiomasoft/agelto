<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Services\Llm\Contracts\OutboundProxy;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Process\DatabaseFailureClassifier;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/** SDK codecs remain in Llm. Their final handler delegates to the sole owner, never a proxy URL. */
final readonly class PoolOutboundProxy implements OutboundProxy
{
    public function __construct(private ProxyResolver $resolver, private LegacyOutbound $outbound,
        private SourceCatalogRepository $catalog) {}

    public function optionsFor(string $host, ?string $providerKey = null): array
    {
        return ['allow_redirects' => false, 'http_errors' => false,
            'handler' => function (RequestInterface $request, array $options) use ($host, $providerKey): PromiseInterface {
                try {
                    $url = new SafeUrl((string) $request->getUri());
                    $provider = $providerKey === null ? $this->catalog->providerForOrigin($url->origin->value())
                        : new ProviderKey($providerKey);
                    if ($url->origin->host !== strtolower($host) || $provider === null) {
                        throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
                    }
                    $headers = [];
                    foreach ($request->getHeaders() as $name => $values) {
                        if (! is_string($name)) {
                            throw new \LogicException('Expected provider header name.');
                        }
                        if (! in_array(strtolower($name), ['host', 'connection', 'content-length', 'transfer-encoding', 'proxy-authorization', 'accept-encoding'], true)) {
                            $headers[$name] = implode(', ', $values);
                        }
                    }
                    $timeout = $options['timeout'] ?? 120;
                    if (! is_int($timeout) && ! is_float($timeout)) {
                        throw new \InvalidArgumentException('Provider timeout must be numeric.');
                    }
                    $readOnly = in_array($request->getMethod(), ['GET', 'HEAD'], true);
                    $response = $this->outbound->request(callerCode: 'legacy.provider', platformId: null, providerKey: $provider,
                        capability: 'provider', url: $url, trafficClass: TrafficClass::Provider,
                        routePolicy: $this->resolver->profile(provider: true), controls: ['system', 'provider:'.$provider->value],
                        deadlineSeconds: min(420, max(1, (int) $timeout)), method: $request->getMethod(),
                        payload: new HttpRequestPayload(headers: $headers, body: $readOnly ? null : (string) $request->getBody()),
                        idempotency: $readOnly ? IdempotencyClass::ReadOnly : IdempotencyClass::AmbiguousWrite);

                    return new FulfilledPromise($response->toPsrResponse());
                } catch (\Throwable $failure) {
                    if (! DatabaseFailureClassifier::isTransient($failure)
                        && ! $failure instanceof DependencyUnavailable) {
                        throw $failure;
                    }
                    throw OutboundUnavailable::dependency();
                }
            }];
    }
}
