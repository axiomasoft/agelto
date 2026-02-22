<?php

declare(strict_types=1);

namespace Platform\Dto\PromtRule;

enum Step: string
{
    // Глобальные
    case Global = 'global';

    // Первичный
    case GlobalPrimary = 'global_primary';
    case PrimaryAnalysis = 'primary_analysis';

    // Анализ сметы
    case GlobalEstimate = 'global_estimate';
    case EstimatePreview = 'estimate_preview';
    case EstimateFetchItems = 'estimate_fetch_items';
    case EstimateFetchInfo = 'estimate_fetch_info';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Global->value => 'Глобальные',
            self::GlobalPrimary->value => 'Схемы первичного анализа',
            self::PrimaryAnalysis->value => 'Первичный анализ',
            self::GlobalEstimate->value => 'Схемы смет',
            self::EstimatePreview->value => 'Предварительный анализ сметы',
            self::EstimateFetchItems->value => 'Парсинг позиций сметы',
            self::EstimateFetchInfo->value => 'Парсинг итогов сметы',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }
}
