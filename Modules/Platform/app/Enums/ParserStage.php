<?php

declare(strict_types=1);

namespace Platform\Enums;

use Filament\Support\Contracts\HasLabel;

enum ParserStage: string implements HasLabel
{
    case MAP = 'map';                       // Сбор ссылок
    case DOC_PARSE = 'doc_parse';           // Сбор документов из карточки
    case DOC_ANALYSIS = 'doc_analysis';     // Скрининг/Анализ ИИ (Gemini)
    case ORDER_PARSE = 'order_parse';       // Финальный парсинг позиций в БД

    public function getLabel(): ?string
    {
        return match ($this) {
            self::MAP => 'Сбор ссылок',
            self::DOC_PARSE => 'Сбор документов из карточки',
            self::DOC_ANALYSIS => 'Скрининг/Анализ ИИ (Gemini)',
            self::ORDER_PARSE => 'Финальный парсинг позиций в БД',
        };
    }
}
