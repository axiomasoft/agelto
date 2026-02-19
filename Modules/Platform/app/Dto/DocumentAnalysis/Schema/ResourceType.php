<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

enum ResourceType: string
{
    case Material = 'Material';
    case Machine = 'Machine';
    case Labor = 'Labor';
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
