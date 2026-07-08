<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Причина терминального `DocumentStatus::Unprocessable` (D32/D38). Первый по порядку исполнения
 * потребитель — P4.2 (`too_large` xlsx size-gate, `zip_bomb` архив); P5.1 дополняет
 * `unreadable_format`/`password_protected` (qpdf-слайсер, битый/зашифрованный PDF или формат,
 * не поддержанный скринингом); P6.1 дополняет `provider_limit_size`/`provider_limit_pages`
 * (preflight-отказ ДО upload ИЛИ рантайм-ошибка провайдера о лимите, D29, `docs/specs/v2/
 * 08-estimate-extraction.md` §4в/§4г) — крайний случай, когда файл превышает лимиты активного
 * маршрута даже для оконного пути (windowed выключен/тоже не годится).
 */
enum UnprocessableReason: string
{
    case TooLarge = 'too_large';
    case ZipBomb = 'zip_bomb';
    case UnreadableFormat = 'unreadable_format';
    case PasswordProtected = 'password_protected';
    case ProviderLimitSize = 'provider_limit_size';
    case ProviderLimitPages = 'provider_limit_pages';
}
