<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

/**
 * Стадия обработки документа в конвейере (обнаружен → скачан → подготовлен → проверен → извлечён).
 */
enum DocumentStatus: string
{
    case Discovered = 'discovered';
    case Downloaded = 'downloaded';
    case Prepared = 'prepared';
    case Screened = 'screened';
    case Extracted = 'extracted';
    case Ignored = 'ignored';
    case Failed = 'failed';

    /**
     * Терминальный исход «не можем обработать» (D32/D38): отдельный от `failed` статус —
     * констатация лимита/формата, не сбой, не ретраится; причина — `last_error`
     * (`App\Enums\Procurement\Document\UnprocessableReason`).
     */
    case Unprocessable = 'unprocessable';
}
