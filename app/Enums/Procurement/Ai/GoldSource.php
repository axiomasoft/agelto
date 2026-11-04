<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

/**
 * Способ получения `Fixture::gold_path`.
 * - Deterministic: собран парсером без LLM (структурная модель ГрандСметы и т. п.).
 * - LlmAssisted: собран с участием LLM и подтверждён человеком (HITL).
 * - None: gold не предполагается (негатив).
 */
enum GoldSource: string
{
    case Deterministic = 'deterministic';
    case LlmAssisted = 'llm_assisted';
    case None = 'none';
}
