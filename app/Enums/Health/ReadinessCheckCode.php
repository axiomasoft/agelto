<?php

declare(strict_types=1);

namespace App\Enums\Health;

enum ReadinessCheckCode: string
{
    case Database = 'database';
    case Cache = 'cache';
    case Storage = 'storage';
    case ReleaseState = 'release_state';
    case Assets = 'assets';
    case Horizon = 'horizon';
    case Scheduler = 'scheduler';
}
