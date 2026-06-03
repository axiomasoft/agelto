<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema;

use App\Attributes\Alias;

/** Классификация ресурса. */
enum EstimateResourceType: string
{
    #[Alias(aliases: ['МАТ', 'мат.ресурс', 'ФССЦ', 'прайс-листы'])]
    case Material = 'Material';

    #[Alias(aliases: ['ЭМ', 'маш.час', 'Машины и механизмы', 'ФСЭМ'])]
    case Machine = 'Machine';

    #[Alias(aliases: ['ЗП', 'ОТ', 'ЗПМ', 'Трудозатраты', 'чел.час'])]
    case Labor = 'Labor';

    #[Alias(aliases: ['НР', 'СП', 'Накладные расходы', 'Прибыль'])]
    case Overhead = 'Overhead';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Material->value => 'Материалы',
            self::Machine->value => 'Машины и механизмы',
            self::Labor->value => 'Трудозатраты',
            self::Overhead->value => 'Накладные расходы / Прибыль',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }
}
