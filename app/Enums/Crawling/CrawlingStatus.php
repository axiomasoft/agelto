<?php

declare(strict_types=1);

namespace App\Enums\Crawling;

use Filament\Support\Contracts\HasLabel;

enum CrawlingStatus: string implements HasLabel
{
    case PENDING = 'pending';       // Ожидает парсинга документов
    case PROCESSING = 'processing'; // В процессе (DocParse)
    case COMPLETED = 'completed';   // Документы успешно собраны
    case FAILED = 'failed';        // Ошибка (превышены попытки или критический сбой)

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::PROCESSING => 'В работе',
            self::COMPLETED => 'Завершено',
            self::FAILED => 'Ошибка',
        };
    }
}
