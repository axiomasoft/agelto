<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProcessStage: string implements HasLabel
{
    case Crawling = 'crawling';                       // Сбор ссылок
    case DocParsing = 'parsing_docs';           // Сбор документов из карточки
    case DocAnalysis = 'doc_analysis';     // Скрининг/Анализ ИИ (Gemini)
    case ParsingFull = 'parsing_full';       // Финальный парсинг позиций в БД

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Crawling => 'Сбор ссылок',
            self::DocParsing => 'Сбор документов из карточки',
            self::DocAnalysis => 'Скрининг/Анализ ИИ (Gemini)',
            self::ParsingFull => 'Финальный парсинг позиций в БД',
        };
    }
}
