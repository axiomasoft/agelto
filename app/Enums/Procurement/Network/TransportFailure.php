<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/** Physical transport facts, not item failures or a diagnosis of source health. */
enum TransportFailure: string
{
    case Connect = 'connect';
    case Timeout = 'timeout';
    case ProxyConnect = 'proxy_connect';
    case ProxyAuth = 'proxy_auth';
    case Tls = 'tls';
    case Truncated = 'truncated';
    case Decode = 'decode';
    case ByteLimit = 'byte_limit';
    case Cancelled = 'cancelled';
    case Storage = 'storage';
    case Configuration = 'configuration';

    public function retryable(): bool
    {
        return in_array($this, [self::Connect, self::Timeout, self::ProxyConnect, self::Truncated], true);
    }
}
