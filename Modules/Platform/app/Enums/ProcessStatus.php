<?php

declare(strict_types=1);

namespace Platform\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProcessStatus: string implements HasLabel
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
