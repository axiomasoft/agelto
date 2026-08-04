<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

/**
 * Шаг AI-анализа версии документа.
 */
enum AnalysisStep: string
{
    case Screening = 'screening';
    case Extraction = 'extraction';
}
