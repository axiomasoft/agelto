<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Причина терминального `DocumentStatus::Unprocessable` (D32/D38). Первый по порядку исполнения
 * потребитель — P4.2 (`too_large` xlsx size-gate, `zip_bomb` архив); P5.1 дополняет
 * `unreadable_format`/`password_protected`, P6.1 — `provider_limit_size`/`provider_limit_pages`.
 */
enum UnprocessableReason: string
{
    case TooLarge = 'too_large';
    case ZipBomb = 'zip_bomb';
}
