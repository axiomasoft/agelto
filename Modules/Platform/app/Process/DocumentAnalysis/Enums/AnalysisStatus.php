<?php

declare(strict_types=1);

namespace Platform\Enums;

use Filament\Support\Contracts\HasLabel;

enum AnalysisStatus: string implements HasLabel
{
    // Статусы подготовки файла для анализа
    case Preparing = 'preparing';
    case Prepared = 'prepared';

        // Первичный анализ
    case PrimaryAnalyzing = 'primary_analyzing';
    case PrimaryAnalyzed = 'primary_analyzed';
    case PrimaryIgnored = 'primary_ignored';
    case PrimaryAnalysisFailed = 'primary_analysis_failed';

        // Анализ сметы
    case EstimateAnalyzing = 'estimate_analyzing';
    case EstimateAnalyzed = 'estimate_analyzed';
    case EstimateAnalysisFailed = 'estimate_analysis_failed';

        // Статусы завершения анализа
    case Completed = 'completed';

        // Статусы ошибок
    case Error = 'error';

    public function getLabel(): ?string
    {
        return match ($this) {
            // Статусы подготовки файла для анализа
            self::Preparing => 'Подготовка',
            self::Prepared => 'Подготовлен',

            // Первичный анализ
            self::PrimaryAnalyzing => 'Анализ начат',
            self::PrimaryAnalyzed => 'Анализ завершен',
            self::PrimaryIgnored => 'Файл не интересен',
            self::PrimaryAnalysisFailed => 'Ошибка первичного анализа',

            // Анализ сметы
            self::EstimateAnalyzing => 'Анализ сметы начат',
            self::EstimateAnalyzed => 'Анализ сметы завершен',
            self::EstimateAnalysisFailed => 'Ошибка анализа сметы',

            // Статусы завершения анализа
            self::Completed => 'Анализ файла завершен',

            // Статусы ошибок
            self::Error => 'Ошибка',
        };
    }
}
