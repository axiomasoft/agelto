<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use App\Enums\Procurement\Estimate\EstimateTarget;
use App\Enums\Procurement\Estimate\EstimateType;
use Spatie\LaravelData\Data;

/**
 * Паспорт сметы — прямое соответствие jsonb-колонке prc_estimates.passport (docs/specs/v2/
 * 08-estimate-extraction.md §1.1/§1.3). Не участвует в фильтрах напрямую — поэтому храним объектом,
 * а не отдельными колонками.
 */
final class EstimatePassport extends Data
{
    public function __construct(
        #[Description('тип сметного документа — local_estimate/act/resource_calculation/defect_list, если не уверена — null.')]
        public ?EstimateType $type = null,
        #[Description('обоснование/ссылка на нормативный документ, если есть в тексте.')]
        public ?string $reference = null,
        #[Description('направление объекта — construction/renovation/medical_equipment/engineering.')]
        public ?EstimateTarget $target = null,
        #[Description('адрес объекта, если указан.')]
        public ?string $address = null,
        #[Description('сметная программа, которой сформирован документ (напр. «Гранд-Смета», «1С:Смета») — диагностическое поле, не участвует в бизнес-логике.')]
        public ?string $generator = null,
        #[Description('номер договора подряда, если документ его называет.')]
        public ?string $contract_number = null,
        #[Description('дата договора подряда, ISO-строка — цитата документа, не парсится в дату на бэкенде.')]
        public ?string $contract_date = null,
    ) {}
}
