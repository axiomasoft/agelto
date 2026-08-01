<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use App\Enums\Procurement\ModifierKind;
use Spatie\LaravelData\Data;

/**
 * Коэффициент/индекс позиции (напр. К=1.15, перенос v1 EstimateModifierItem, docs/specs/v2/
 * 08-estimate-extraction.md §1.4/§4.2). Низкая частота — не отдельная таблица, персистится в
 * `prc_estimate_items.attributes->'modifiers'` (jsonb, фиксированная схема).
 */
final class RowModifier extends Data
{
    public function __construct(
        #[Description('подпись коэффициента из документа (напр. «К=1.15»).')]
        public string $name,
        #[Description('машиночитаемый род коэффициента — к количеству/к стоимости/поправочный/зимнее удорожание/пересчёт; свободный текст обоснования сюда не переносится — род выражается ЭТИМ полем, а не прозой (обоснование остаётся в code/name).')]
        public ?ModifierKind $kind = null,
        #[Description('шифр/обоснование коэффициента, если указано в документе.')]
        public ?string $code = null,
        #[Description('скалярное значение коэффициента, форма книг (decimal-строка — конверсия во float запрещена); null, если документ несёт только составляющие (форма .gsfx).')]
        public ?string $value = null,
        #[Description('составляющая коэффициента по оплате труда (форма .gsfx); в форме книг не заполняется (там один скаляр value).')]
        public ?string $value_labor = null,
        #[Description('составляющая коэффициента по эксплуатации машин (форма .gsfx); в форме книг не заполняется (там один скаляр value).')]
        public ?string $value_machines = null,
        #[Description('составляющая коэффициента по зарплате машинистов (форма .gsfx); в форме книг не заполняется (там один скаляр value).')]
        public ?string $value_machinists_wage = null,
        #[Description('составляющая коэффициента по материалам (форма .gsfx); в форме книг не заполняется (там один скаляр value).')]
        public ?string $value_materials = null,
        #[Description('уровень применения коэффициента (форма .gsfx), если указан.')]
        public ?int $level = null,
    ) {}
}
