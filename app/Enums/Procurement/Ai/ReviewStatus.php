<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

/**
 * Статус операторского ревью AI-анализа: unreviewed → approved/rejected.
 */
enum ReviewStatus: string
{
    case Unreviewed = 'unreviewed';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
