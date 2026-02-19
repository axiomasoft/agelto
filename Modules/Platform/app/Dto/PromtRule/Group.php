<?php

declare(strict_types=1);

namespace Platform\Dto\PromtRule;

enum Group: string
{
    case Global = 'global';
    case PrimaryAnalysis = 'primary_analysis';
    case ColumnMapping = 'column_mapping';
    case DetailedParsing = 'detailed_parsing';
    case DocumentFullParsing = 'document_full_parsing';
    case FooterAnalysis = 'footer_analysis';
    case DataCleaning = 'data_cleaning';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Global->value => 'Глобальные',
            self::PrimaryAnalysis->value => 'Первичный анализ',
            self::ColumnMapping->value => 'Маппинг колонок',
            self::DetailedParsing->value => 'Детальный парсинг',
            self::DocumentFullParsing->value => 'Полный парсинг документа',
            self::FooterAnalysis->value => 'Анализ подвала',
            self::DataCleaning->value => 'Очистка данных',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }
}
