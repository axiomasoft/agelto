<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/** Explicit synchronous cURL pump with deterministic release when an idle callback throws. */
final class PinnedCurlHandler
{
    private CurlFactory $factory;

    private int $connectStatus = 0;

    private bool $consumed = false;

    public function __construct()
    {
        if (! extension_loaded('curl') || ! defined('CURLOPT_CONNECT_TO') || ! defined('CURLOPT_LOW_SPEED_TIME')) {
            throw new \LogicException('Pinned cURL transport capabilities unavailable.');
        }
        $this->factory = new CurlFactory(0);
    }

    /** PHP's aggregate curl_getinfo array does not expose CURLINFO_HTTP_CONNECTCODE. */
    public function proxyConnectStatus(): int
    {
        return $this->connectStatus;
    }

    /** @param array<array-key, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        // CurlFactory::finish may re-enter the handler on an ambiguous rewind failure.
        // One pump is one physical request; only the outer owner can authorize another.
        if ($this->consumed) {
            throw new \RuntimeException('The physical cURL pump is already consumed.');
        }
        $this->consumed = true;
        $easy = $this->factory->create($request, $options);
        if (! $easy->handle instanceof \CurlHandle) {
            throw new \LogicException('Expected a native cURL handle.');
        }
        $finished = false;
        try {
            curl_exec($easy->handle);
            $easy->errno = curl_errno($easy->handle);
            $this->connectStatus = curl_getinfo($easy->handle, CURLINFO_HTTP_CONNECTCODE);
            $finished = true;

            return CurlFactory::finish($this, $easy, $this->factory);
        } finally {
            if (! $finished) {
                $this->factory->release($easy);
            }
        }
    }
}
