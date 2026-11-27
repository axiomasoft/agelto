<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Направление/категория объекта сметы (поле target в EstimatePassport,
 * docs/specs/v2/08-estimate-extraction.md §3.5).
 * - Construction: Строительство.
 * - Renovation: Ремонт.
 * - MedicalEquipment: Медоборудование.
 * - Engineering: Инженерия.
 */
enum EstimateTarget: string
{
    case Construction = 'construction';
    case Renovation = 'renovation';
    case MedicalEquipment = 'medical_equipment';
    case Engineering = 'engineering';
}
