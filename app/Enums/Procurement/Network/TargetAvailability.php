<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/**
 * Подтверждённая доступность функции target (platform-transport#s06) отдельно от circuit state:
 * suspect не равен outage и не запрещает следующую разрешённую попытку.
 */
enum TargetAvailability: string
{
    case Unknown = 'unknown';
    case Available = 'available';
    case Suspect = 'suspect';
    case Unavailable = 'unavailable';
}
