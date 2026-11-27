<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

use Filament\Support\Contracts\HasLabel;

/**
 * Канонический закрытый набор статусов закупки. Bidding/Evaluation/Completed/Cancelled
 * соответствуют этапам источника; Unknown сохраняет незнакомое отображение.
 * Awarded и Unsuccessful представляют результаты определения поставщика и могут
 * заполняться detail-слоем, когда источник содержит достаточные факты.
 */
enum ProcurementStatus: string implements HasLabel
{
    case Bidding = 'bidding';
    case Evaluation = 'evaluation';
    case Awarded = 'awarded';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Unsuccessful = 'unsuccessful';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bidding => 'Подача заявок',
            self::Evaluation => 'Работа комиссии',
            self::Awarded => 'Поставщик определён',
            self::Completed => 'Завершена',
            self::Cancelled => 'Отменена',
            self::Unsuccessful => 'Не состоялась',
            self::Unknown => 'Не определён',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::Unsuccessful => true,
            default => false,
        };
    }

    public function isFailed(): bool
    {
        return match ($this) {
            self::Cancelled, self::Unsuccessful => true,
            default => false,
        };
    }

    public function isPositiveTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Awarded => true,
            default => false,
        };
    }
}
