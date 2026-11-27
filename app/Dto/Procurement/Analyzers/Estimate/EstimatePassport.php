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
        #[Description('основание составления сметы — проектная и (или) иная техническая документация, задание, договор (форма 421/пр, строка «Основание»); НЕ редакция сметных нормативов — та живёт в PassportResponse::$regulatory_base.')]
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
        #[Description('реквизиты приказа Минстроя России о дополнениях к редакции сметных нормативов — ЦИТАТА документа.')]
        public ?string $regulatory_amendments = null,
        #[Description('реквизиты письма Минстроя России об индексах изменения сметной стоимости — ЦИТАТА документа.')]
        public ?string $index_letter = null,
    ) {}
}
