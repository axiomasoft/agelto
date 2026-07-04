<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Итоговый статус закупки/лота по результатам подведения итогов.
 */
enum AwardStatus: string
{
    case Awarded = 'awarded';
    case Unsuccessful = 'unsuccessful';
    case Cancelled = 'cancelled';
}
