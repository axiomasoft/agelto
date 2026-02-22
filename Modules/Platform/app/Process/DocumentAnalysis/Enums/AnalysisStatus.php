<?php

declare(strict_types=1);

namespace Platform\Enums;

use Filament\Support\Contracts\HasLabel;

enum AnalysisStatus: string implements HasLabel
{
    // Статусы подготовки файла для анализа
    case Preparing = 'preparing';
    case Prepared = 'prepared';

        // Статусы анализа
    case Analyzing = 'analyzing';
    case Analyzed = 'analyzed';
    case Ignored = 'ignored';
    case Completed = 'completed';

        // Статусы ошибок
    case Error = 'error';
    case AnalysisFailed = 'analysis_failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Preparing => 'Подготовка',
            self::Prepared => 'Подготовлен',
            self::Analyzing => 'Анализ начат',
            self::Analyzed => 'Анализ завершен',
            self::Ignored => 'Файл не интересен',
            self::Completed => 'Анализ файла завершен',
            self::AnalysisFailed => 'Ошибка анализа',
            self::Error => 'Ошибка',
        };
    }
}
