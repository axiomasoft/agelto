<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Proxy;

/**
 * Причины отказа proxy node. Уровень реакции — глобальный или для конкретной цели —
 * выбирает health policy, отдельно от самой классификации причины.
 */
enum ProxyFailureReason: string
{
    case NetworkTimeout = 'network_timeout';
    case TlsError = 'tls_error';
    case ConnectFailed = 'connect_failed';
    case ProxyAuthFailed = 'proxy_auth_failed';
    case Http403 = 'http_403';
    case Http429 = 'http_429';
    case EmptyBody = 'empty_body';
    case Unknown = 'unknown';
}
