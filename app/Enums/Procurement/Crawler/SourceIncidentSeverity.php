<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

enum SourceIncidentSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
