<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Результат детерминированной проверки сметы, вычисляемый `EstimateValidator`
 * и сохраняемый в `prc_estimates.validation_status`.
 * Valid: полнота и арифметика в допуске.
 * Suspicious: есть аномалии сумм, пропусков или дублей; данные сохраняются для проверки и re-run.
 * Invalid: существенная потеря данных; результат также сохраняется, но требует первоочередного внимания.
 */
enum EstimateValidationStatus: string
{
    case Valid = 'valid';
    case Suspicious = 'suspicious';
    case Invalid = 'invalid';
}
