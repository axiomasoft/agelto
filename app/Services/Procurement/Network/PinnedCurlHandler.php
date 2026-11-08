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
            $unixSocket = $_ENV['OUTBOUND_PROXY_UNIX_SOCKET'] ?? $_SERVER['OUTBOUND_PROXY_UNIX_SOCKET'] ?? getenv('OUTBOUND_PROXY_UNIX_SOCKET');
            $proxy = $options['proxy'] ?? null;
            if (is_string($unixSocket) && $unixSocket !== '' && is_string($proxy) && $proxy !== '' && file_exists($unixSocket)) {
                $easy->errno = $this->execViaUnixSocket($easy->handle, $unixSocket);
            } else {
                curl_exec($easy->handle);
                $easy->errno = curl_errno($easy->handle);
            }
            $this->connectStatus = curl_getinfo($easy->handle, CURLINFO_HTTP_CONNECTCODE);
            $finished = true;

            return CurlFactory::finish($this, $easy, $this->factory);
        } finally {
            if (! $finished) {
                $this->factory->release($easy);
            }
        }
    }

    private function execViaUnixSocket(\CurlHandle $handle, string $unixSocket): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (! is_resource($server)) {
            return CURLE_COULDNT_CONNECT;
        }
        stream_set_blocking($server, false);
        $address = stream_socket_get_name($server, false);
        if (! is_string($address) || $address === '') {
            fclose($server);

            return CURLE_COULDNT_CONNECT;
        }
        curl_setopt($handle, CURLOPT_PROXY, 'http://'.$address);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $handle);
        $client = null;
        $unix = null;
        $toUnix = '';
        $toClient = '';
        $result = CURLE_OK;
        try {
            $active = 0;
            do {
                $status = curl_multi_exec($multi, $active);
                if ($client === null) {
                    $read = [$server];
                    $write = null;
                    $except = null;
                    if (@stream_select($read, $write, $except, 0, 5_000) > 0) {
                        $accepted = @stream_socket_accept($server, 0);
                        if (is_resource($accepted)) {
                            stream_set_blocking($accepted, false);
                            $connected = @stream_socket_client('unix://'.$unixSocket, $uErr, $uStr, 5);
                            if (! is_resource($connected)) {
                                fclose($accepted);

                                return CURLE_COULDNT_CONNECT;
                            }
                            stream_set_blocking($connected, false);
                            $client = $accepted;
                            $unix = $connected;
                        }
                    }
                }
                if (is_resource($client) && is_resource($unix)) {
                    $read = [$client, $unix];
                    $write = [];
                    if ($toUnix !== '') {
                        $write[] = $unix;
                    }
                    if ($toClient !== '') {
                        $write[] = $client;
                    }
                    $except = null;
                    if (@stream_select($read, $write, $except, 0, 10_000) > 0) {
                        foreach ($read as $stream) {
                            $chunk = @fread($stream, 65_536);
                            if (is_string($chunk) && $chunk !== '') {
                                if ($stream === $client) {
                                    $toUnix .= $chunk;
                                } else {
                                    $toClient .= $chunk;
                                }
                            }
                        }
                        foreach ($write as $stream) {
                            if ($stream === $unix && $toUnix !== '') {
                                $written = @fwrite($unix, $toUnix);
                                if (is_int($written) && $written > 0) {
                                    $toUnix = substr($toUnix, $written);
                                }
                            } elseif ($stream === $client && $toClient !== '') {
                                $written = @fwrite($client, $toClient);
                                if (is_int($written) && $written > 0) {
                                    $toClient = substr($toClient, $written);
                                }
                            }
                        }
                    }
                } else {
                    curl_multi_select($multi, 0.01);
                }
            } while ($active > 0 && $status === CURLM_OK);
            while (($info = curl_multi_info_read($multi)) !== false) {
                if (($info['handle'] ?? null) === $handle && is_int($info['result'] ?? null)) {
                    $result = $info['result'];
                }
            }

            return $result;
        } finally {
            curl_multi_remove_handle($multi, $handle);
            curl_multi_close($multi);
            if (is_resource($client)) {
                fclose($client);
            }
            if (is_resource($unix)) {
                fclose($unix);
            }
            fclose($server);
        }
    }
}
