<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Proxy;

/**
 * Таксономия причин отказа прокси-узла; уровень реакции (глобальный/целевой) — research/02 §5,
 * реализация автомата breaker'а по уровню — P1.4.
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
