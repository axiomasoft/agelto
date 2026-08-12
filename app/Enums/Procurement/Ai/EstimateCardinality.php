<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

enum EstimateCardinality: string
{
    case None = 'none';
    case Single = 'single';
    case Multiple = 'multiple';
    case Unknown = 'unknown';
}
