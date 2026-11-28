<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

/**
 * Класс эталона реестра `prc_ai_fixtures` — совпадает с префиксом кода эталона.
 * - Estimate: позитив — документ, из которого извлекаются строки сметы.
 * - Negative: негатив — документ, который пайплайн обязан НЕ распознать как смету.
 */
enum FixtureClass: string
{
    case Estimate = 'est';
    case Negative = 'neg';
}
