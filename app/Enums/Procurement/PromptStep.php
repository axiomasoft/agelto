<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Шаг композиции системной инструкции (`prc_ai_prompts.step`), формат v1 (глобальный →
 * шаг-глобальный → конкретный шаг). `ExtractionDiscovery` — маркеры границ смет оконного пути
 * (P6.4, D30/D37), собственное значение, не переиспользует `Screening` (Audit P6, D37).
 */
enum PromptStep: string
{
    case Global = 'global';
    case ScreeningGlobal = 'screening:global';
    case ScreeningClassify = 'screening:classify';
    case ExtractionGlobal = 'extraction:global';
    case ExtractionDiscovery = 'extraction:discovery';
    case ExtractionPassport = 'extraction:passport';
    case ExtractionRows = 'extraction:rows';
    case ExtractionTotals = 'extraction:totals';
}
