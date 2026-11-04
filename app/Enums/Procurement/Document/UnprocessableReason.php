<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

/**
 * Причина терминального `DocumentStatus::Unprocessable`.
 *
 * Подготовка отклоняет слишком большие таблицы (`too_large`), архивные бомбы (`zip_bomb`),
 * нечитаемые и зашифрованные документы (`unreadable_format`/`password_protected`).
 * Извлечение фиксирует `provider_limit_size`/`provider_limit_pages`, когда файл превышает
 * лимиты активного маршрута даже для оконного пути (он выключен или тоже не подходит).
 * Это может быть preflight-отказ до upload или ответ провайдера о лимите.
 *
 * Исчерпание стратегий подготовки даёт `conversion_failed`, `ocr_failed` или
 * `archive_unsupported`. Значения кейсов сохраняются в `last_error` и не переименовываются.
 */
enum UnprocessableReason: string
{
    case UnsupportedFormat = 'unsupported_format';
    case FormatMismatch = 'format_mismatch';
    case TooLarge = 'too_large';
    case ZipBomb = 'zip_bomb';
    case UnreadableFormat = 'unreadable_format';
    case PasswordProtected = 'password_protected';
    case ProviderLimitSize = 'provider_limit_size';
    case ProviderLimitPages = 'provider_limit_pages';
    case ConversionFailed = 'conversion_failed';
    case OcrFailed = 'ocr_failed';
    case ArchiveUnsupported = 'archive_unsupported';
}
