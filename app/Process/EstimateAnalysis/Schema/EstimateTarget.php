<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema;

/**
 * Направление/категория сметы (поле target в EstimatePassport).
 * - construction: Строительство.
 * - renovation: Ремонт.
 * - medical_equipment: Медоборудование.
 * - engineering: Инженерия.
 */
enum EstimateTarget: string
{
    case Construction = 'construction';
    case Renovation = 'renovation';
    case MedicalEquipment = 'medical_equipment';
    case Engineering = 'engineering';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Construction->value => 'Строительство',
            self::Renovation->value => 'Ремонт',
            self::MedicalEquipment->value => 'Медоборудование',
            self::Engineering->value => 'Инженерия',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }
}
