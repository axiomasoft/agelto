<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

enum PageTextStatus: string
{
    case NativeText = 'native_text';
    case OcrText = 'ocr_text';
    case ProvedBlank = 'proved_blank';
    case Unrecognized = 'unrecognized';
    case Failed = 'failed';
    case NotAttempted = 'not_attempted';

    public function covered(): bool
    {
        return in_array($this, [self::NativeText, self::OcrText, self::ProvedBlank], true);
    }
}
