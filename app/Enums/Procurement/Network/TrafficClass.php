<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/**
 * Класс трафика physical request: задаётся адаптером по endpoint И
 * намерению. Те же значения закрывает CHECK `prc_network_demands.traffic_class`; probe — отдельный
 * DemandPurpose, а не класс трафика.
 */
enum TrafficClass: string
{
    case FreshListing = 'fresh_listing';
    case StatusRefresh = 'status_refresh';
    case Detail = 'detail';
    case ArchiveListing = 'archive_listing';
    case Download = 'download';
    case ReferenceSync = 'reference_sync';
    case Diagnostic = 'diagnostic';
    case Provider = 'provider';
}
