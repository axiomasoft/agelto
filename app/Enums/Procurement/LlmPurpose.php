<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Назначение вызова LLM — общий словарь для `prc_ai_llm_calls.purpose` и `prc_ai_routes.purpose`.
 */
enum LlmPurpose: string
{
    case Screening = 'screening';
    case ExtractPassport = 'extract_passport';
    case ExtractRows = 'extract_rows';
    case ExtractTotals = 'extract_totals';
    case Eval = 'eval';
    case Shadow = 'shadow';
}
