<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Тип сметного документа (поле type в EstimatePassport, docs/specs/v2/08-estimate-extraction.md §3.5).
 * - Estimate: Смета (ЛСР) — «Локальный сметный расчёт», «Локальная смета».
 * - Act: Акт (КС-2) — «Акт о приемке выполненных работ», «Форма № КС-2».
 * - ResourceCalculation: Ресурсная ведомость — «Ведомость ресурсов», «Расчет ресурсов».
 * - DefectList: Дефектная ведомость — «Дефектная ведомость», «Опись работ».
 */
enum EstimateType: string
{
    case Estimate = 'estimate';
    case Act = 'act';
    case ResourceCalculation = 'resource_calculation';
    case DefectList = 'defect_list';
}
