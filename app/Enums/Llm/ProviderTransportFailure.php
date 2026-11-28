<?php

declare(strict_types=1);

namespace App\Enums\Llm;

enum ProviderTransportFailure: string
{
    case Connection = 'connection';
    case ProxyConnect = 'proxy_connect';
    case Tls = 'tls';
    case Timeout = 'timeout';
    case Truncated = 'truncated';
    case Interrupted = 'interrupted';
    case Deadline = 'deadline';
}
