<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

use App\Attributes\Ai\Alias;

/**
 * Классификация ресурса позиции сметы (перенос v1 EstimateResourceType + перенос словаря
 * синонимов, docs/specs/v2/08-estimate-extraction.md §2.2). #[Alias] на кейсах — часть системной
 * инструкции ЛЮБОГО шага, читающего построчные позиции (§2): LLM обязана сопоставлять русские
 * заголовки колонок/категории ресурсов с этими каноническими именами независимо от формулировки
 * в конкретном документе. Other — задел под ресурсы, не попадающие в 4 классических типа v1.
 */
enum ResourceType: string
{
    #[Alias(aliases: ['ЗП', 'ОТ', 'ЗПМ', 'Трудозатраты', 'чел.час', 'ОТ(ЗТ)', 'ОТм(ЗТм)'])]
    case Labor = 'labor';

    #[Alias(aliases: ['ЭМ', 'маш.час', 'Машины и механизмы', 'ФСЭМ'])]
    case Machine = 'machine';

    #[Alias(aliases: ['МАТ', 'мат.ресурс', 'ФССЦ', 'прайс-листы', 'М'])]
    case Material = 'material';

    #[Alias(aliases: ['НР', 'СП', 'Накладные расходы', 'Прибыль'])]
    case Overhead = 'overhead';

    case Other = 'other';
}
