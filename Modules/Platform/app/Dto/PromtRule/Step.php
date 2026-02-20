<?php

declare(strict_types=1);

namespace Platform\Dto\PromtRule;

enum Step: string
{
    case Global = 'global';
    case PrimaryAnalysis = 'primary_analysis';
    case EstimatePreview = 'estimate_preview';
    case EstimateFetchItems = 'estimate_fetch_items';
    case EstimateFetchFooter = 'estimate_fetch_footer';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Global->value => 'Глобальные',
            self::PrimaryAnalysis->value => 'Первичный анализ',
            self::EstimatePreview->value => 'Предварительный анализ сметы',
            self::EstimateFetchItems->value => 'Парсинг позиций сметы',
            self::EstimateFetchFooter->value => 'Парсинг итогов сметы',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }
}
