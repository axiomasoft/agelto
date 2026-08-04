<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

/**
 * Способ получения `Fixture::gold_path` (P1.3, вход в P5).
 * - Deterministic: собран парсером без LLM (структурная модель ГрандСметы и т. п.).
 * - LlmAssisted: собран с участием LLM и подтверждён человеком (HITL, P8.3).
 * - None: gold не предполагается (негатив, D5).
 */
enum GoldSource: string
{
    case Deterministic = 'deterministic';
    case LlmAssisted = 'llm_assisted';
    case None = 'none';
}
