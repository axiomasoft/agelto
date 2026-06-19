<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Pipeline\PipelineStage;
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

    /**
     * Стадия, к которой относится статус. Используется retry, чтобы перезапускать с нужной точки.
     */
    public function stage(): PipelineStage
    {
        return match ($this) {
            self::Preparing, self::Prepared => PipelineStage::DocumentPrepare,
            self::PrimaryAnalyzing, self::PrimaryAnalyzed, self::PrimaryIgnored, self::PrimaryAnalysisFailed => PipelineStage::PrimaryAnalysis,
            self::EstimateAnalyzing, self::EstimateAnalyzed, self::EstimateAnalysisFailed, self::Completed => PipelineStage::EstimateAnalysis,
            self::Error => PipelineStage::DocumentPrepare,
        };
    }

    /** Терминальный успех — обработка завершена, повтор не нужен. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::PrimaryIgnored], true);
    }

    /** Провал — кандидат на retry (или dead-letter при failed_permanently). */
    public function isFailed(): bool
    {
        return in_array($this, [self::Error, self::PrimaryAnalysisFailed, self::EstimateAnalysisFailed], true);
    }

    /** Стадия выполняется прямо сейчас. */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Preparing, self::PrimaryAnalyzing, self::EstimateAnalyzing], true);
    }

    /** Стадия пройдена, запись ждёт диспатча следующей. */
    public function isAwaiting(): bool
    {
        return in_array($this, [self::Prepared, self::PrimaryAnalyzed, self::EstimateAnalyzed], true);
    }
}
