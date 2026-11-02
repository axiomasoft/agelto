<?php

declare(strict_types=1);

namespace App\Enums\Llm;

enum ProviderSendDenial: string
{
    case AlreadyClaimed = 'already_claimed';
    case Revoked = 'revoked';
    case Network = 'network';
    case Financial = 'financial';
    case WireMismatch = 'wire_mismatch';
    case Unsupported = 'unsupported';
    case Dependency = 'dependency';
}
