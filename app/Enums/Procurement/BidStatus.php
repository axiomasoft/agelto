<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Статус заявки/предложения участника по итогам рассмотрения.
 */
enum BidStatus: string
{
    case Admitted = 'admitted';
    case Rejected = 'rejected';
    case Winner = 'winner';
}
