<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Причина, по которой поля строки нельзя считать надёжными (research/12-uncertainty-model.md §2,
 * P8.3). Провенанс различает подмножества: `Unreadable` — единственная причина, которую МОДЕЛЬ
 * вправе вернуть сама (model-origin, EstimateRow::$quality, §3.1); `ArithmeticMismatch`/
 * `NumberingGap` производит только `EstimateValidator` (validator-origin, P8.4) — модель их
 * произвести не вправе, `EstimateRow::validateAndCreate()` отклоняет причину вне model-origin
 * подмножества внутри `quality`.
 */
enum RowQualityReason: string
{
    case Unreadable = 'unreadable';
    case ArithmeticMismatch = 'arithmetic_mismatch';
    case NumberingGap = 'numbering_gap';

    /** Причины, которые модель вправе произвести сама (§3.1 research/12) — сегодня одно значение. */
    public function isModelOrigin(): bool
    {
        return $this === self::Unreadable;
    }
}
