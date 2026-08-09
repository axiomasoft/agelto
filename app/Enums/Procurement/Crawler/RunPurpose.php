<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

/**
 * Причина владения crawler-прогоном.
 *
 * Один running-прогон на crawler остаётся политикой БД. Purpose вместе с subject_key
 * определяет, какой именно оркестратор вправе продолжить его курсор.
 */
enum RunPurpose: string
{
    case Profile = 'profile';
    case Delta = 'delta';
    case Backlog = 'backlog';
}
