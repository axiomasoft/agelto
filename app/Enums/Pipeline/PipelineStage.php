<?php

declare(strict_types=1);

namespace App\Enums\Pipeline;

/**
 * Стадии обработки документа (2–4). Нужна, чтобы retry знал, с какой стадии перезапускать,
 * не прогоняя заново уже пройденное (например, повторный AI-скрининг при провале анализа сметы).
 */
enum PipelineStage: string
{
    case DocumentPrepare = 'document_prepare';
    case PrimaryAnalysis = 'primary_analysis';
    case EstimateAnalysis = 'estimate_analysis';
}
