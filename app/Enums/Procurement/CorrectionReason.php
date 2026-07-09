<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Причина операторской коррекции результата AI-анализа.
 */
enum CorrectionReason: string
{
    case WrongValue = 'wrong_value';
    case MissedRow = 'missed_row';
    case ExtraRow = 'extra_row';
    case WrongType = 'wrong_type';
    case SplitError = 'split_error';
    case Other = 'other';
}
