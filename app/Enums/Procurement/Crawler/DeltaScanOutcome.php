<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

enum DeltaScanOutcome: string
{
    case Processed = 'processed';
    case Busy = 'busy';
    case MissingArchiveProfile = 'missing_archive_profile';
}
