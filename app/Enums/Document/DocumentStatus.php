<?php

declare(strict_types=1);

namespace App\Enums\Document;

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
}
