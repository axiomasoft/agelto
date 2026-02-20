<?php

declare(strict_types=1);

namespace Platform\Dto\PromtRule;

enum Step: string
{
    case Global = 'global';
    case PrimaryAnalysis = 'primary_analysis';
    case DetailedParsing = 'detailed_parsing';
    case DocumentFullParsing = 'document_full_parsing';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Global->value => 'Глобальные',
            self::PrimaryAnalysis->value => 'Первичный анализ',
            self::DetailedParsing->value => 'Детальный парсинг',
            self::DocumentFullParsing->value => 'Полный парсинг документа',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }
}
