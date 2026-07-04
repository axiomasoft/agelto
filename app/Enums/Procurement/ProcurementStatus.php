<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Канонический статус закупки (закрытый codelist, по мотивам OCDS tenderStatus).
 */
enum ProcurementStatus: string
{
    case Announced = 'announced';
    case Bidding = 'bidding';
    case Evaluation = 'evaluation';
    case Awarded = 'awarded';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Unsuccessful = 'unsuccessful';
    case Unknown = 'unknown';

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
}
