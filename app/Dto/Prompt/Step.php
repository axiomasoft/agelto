<?php

declare(strict_types=1);

namespace App\Dto\Prompt;

enum Step: string
{
    // Глобальные
    case Global = 'global';

    // Первичный
    case GlobalPrimary = 'document_analysis:global';
    case PrimaryAnalysis = 'document_analysis:primary';

    // Анализ сметы
    case GlobalEstimate = 'estimate_analysis:global';
    case EstimatePreview = 'estimate_analysis:preview';
    case EstimateFetchInfo = 'estimate_analysis:fetch_info';
    case EstimateFetchItems = 'estimate_analysis:fetch_items';

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
