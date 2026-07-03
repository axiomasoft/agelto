<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema;

/**
 * Тип документа (поле type в EstimatePassport).
 * - estimate: Смета (ЛСР). По заголовкам «Локальный сметный расчёт», «Локальная смета».
 * - act: Акт (КС-2). «Акт о приемке выполненных работ», «Форма № КС-2».
 * - resource_calculation: Ресурсная ведомость. «Ведомость ресурсов», «Расчет ресурсов».
 * - defect_list: Дефектная ведомость. «Дефектная ведомость», «Опись работ».
 */
enum EstimateType: string
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
