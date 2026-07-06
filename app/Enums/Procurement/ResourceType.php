<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Классификация ресурса позиции сметы (перенос идеи v1 EstimateResourceType).
 */
enum ResourceType: string
{
    case Labor = 'labor';
    case Machine = 'machine';
    case Material = 'material';
    case Overhead = 'overhead';
    case Other = 'other';
}
