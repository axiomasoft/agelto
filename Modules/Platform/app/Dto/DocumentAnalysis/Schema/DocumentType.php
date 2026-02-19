<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

enum DocumentType: string
{
    case Estimate = 'estimate';
    case Act = 'act';
    case ResourceCalculation = 'resource_calculation';
    case DefectList = 'defect_list';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Estimate->value => 'Смета (ЛСР)',
            self::Act->value => 'Акт (КС-2)',
            self::ResourceCalculation->value => 'Ресурсная ведомость',
            self::DefectList->value => 'Дефектная ведомость',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }
}
