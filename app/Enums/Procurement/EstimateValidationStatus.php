<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Итог детерминированной валидации извлечённой сметы (`docs/specs/v2/08-estimate-extraction.md`
 * §4.1 п.6, §6). Пишется в `prc_estimates.validation_status` (text-колонка, P6.3) —
 * `EstimateValidator` (P6.2) вычисляет значение, персист — вне scope P6.2.
 * - Valid: полнота (rows_expected vs извлечено) и арифметика (раздел≈subtotal, итоги) в допуске.
 * - Suspicious: есть аномалии (расхождение сумм/пропуски/дубли), но данные сохраняются целиком —
 *   кандидат на точечный re-run (P6.3 RerunRangeAction) или операторское ревью.
 * - Invalid: существенная потеря данных (сильное расхождение rows_expected/извлечено) — данные
 *   ВСЁ РАВНО не отбрасываются (Code Guidance P6.2), но сигнал требует внимания раньше suspicious.
 */
enum EstimateValidationStatus: string
{
    case Valid = 'valid';
    case Suspicious = 'suspicious';
    case Invalid = 'invalid';
}
