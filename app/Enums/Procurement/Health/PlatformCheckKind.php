<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Health;

enum PlatformCheckKind: string
{
    case ListingFilter = 'listing_filter';
    case CardParse = 'card_parse';
    case ListingShape = 'listing_shape';
    case EstimateFields = 'estimate_fields';
}
