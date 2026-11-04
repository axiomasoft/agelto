<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

use App\Attributes\Ai\ExcludeFromProviderSchema;

/**
 * Причина недостоверности полей строки. Модель вправе вернуть только Unreadable.
 * ArithmeticMismatch, NumberingGap и RequiredFieldMissing создаёт `EstimateValidator`.
 * `EstimateRow::validateAndCreate()` отклоняет validator-origin причины в ответе модели.
 */
enum RowQualityReason: string
{
    case Unreadable = 'unreadable';
    case ArithmeticMismatch = 'arithmetic_mismatch';
    case NumberingGap = 'numbering_gap';
    #[ExcludeFromProviderSchema]
    case RequiredFieldMissing = 'required_field_missing';

    /** Причины, которые модель вправе вернуть сама. */
    public function isModelOrigin(): bool
    {
        return $this === self::Unreadable;
    }

    /** Человекочитаемая подпись для операторской витрины — единственный носитель перевода. */
    public function label(): string
    {
        return match ($this) {
            self::Unreadable => 'не удалось прочитать в источнике',
            self::ArithmeticMismatch => 'арифметика раздела не сходится',
            self::NumberingGap => 'нарушена нумерация строк',
            self::RequiredFieldMissing => 'не заполнено обязательное поле',
        };
    }
}
