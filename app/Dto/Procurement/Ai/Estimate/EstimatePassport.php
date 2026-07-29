<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use App\Enums\Procurement\EstimateTarget;
use App\Enums\Procurement\EstimateType;
use Spatie\LaravelData\Data;

/**
 * Паспорт сметы — прямое соответствие jsonb-колонке prc_estimates.passport (docs/specs/v2/
 * 08-estimate-extraction.md §1.1/§1.3). Не участвует в фильтрах напрямую — поэтому храним объектом,
 * а не отдельными колонками.
 * - type: тип сметного документа — local_estimate/act/resource_calculation/defect_list, если не уверена — null.
 * - reference: обоснование/ссылка на нормативный документ, если есть в тексте.
 * - target: направление объекта — construction/renovation/medical_equipment/engineering.
 * - address: адрес объекта, если указан.
 * - generator: сметная программа, которой сформирован документ (напр. «Гранд-Смета», «1С:Смета») —
 *   диагностическое поле, не участвует в бизнес-логике.
 * - contract_number: номер договора подряда, если документ его называет (SR-12, P4.5).
 * - contract_date: дата договора подряда, ISO-строка (SR-12, P4.5) — цитата документа, не
 *   парсится в дату на бэкенде.
 */
final class EstimatePassport extends Data
{
    public function __construct(
        public ?EstimateType $type = null,
        public ?string $reference = null,
        public ?EstimateTarget $target = null,
        public ?string $address = null,
        public ?string $generator = null,
        public ?string $contract_number = null,
        public ?string $contract_date = null,
    ) {}
}
