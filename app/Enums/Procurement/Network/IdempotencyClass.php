<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/**
 * Класс идемпотентности physical request (platform-transport#s05): только read_only допускает
 * bounded retry; ambiguous_write получает максимум один physical send без автоматического повтора.
 */
enum IdempotencyClass: string
{
    case ReadOnly = 'read_only';
    case ProviderIdempotent = 'provider_idempotent';
    case AmbiguousWrite = 'ambiguous_write';

    public function allowsTransportRetry(): bool
    {
        return $this === self::ReadOnly;
    }
}
