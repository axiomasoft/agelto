<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\ModifierKind;
use Spatie\LaravelData\Data;

/**
 * Коэффициент/индекс позиции (напр. К=1.15, перенос v1 EstimateModifierItem, docs/specs/v2/
 * 08-estimate-extraction.md §1.4/§4.2). Низкая частота — не отдельная таблица, персистится в
 * `prc_estimate_items.attributes->'modifiers'` (jsonb, фиксированная схема — P4.5,
 * `plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §4.2/§5.3).
 * - kind: машиночитаемый род коэффициента — к количеству/к стоимости/поправочный/зимнее
 *   удорожание/пересчёт (SR-04/SR-05); `description` (свободный текст обоснования) сюда не
 *   переносится — род выражается ЭТИМ полем, а не прозой (обоснование остаётся в `code`/`name`).
 * - code: шифр/обоснование коэффициента, если указано в документе.
 * - name: подпись коэффициента из документа (напр. «К=1.15»).
 * - value: скалярное значение коэффициента, форма книг (decimal-строка — конверсия во float
 *   запрещена); null, если документ несёт только составляющие (см. ниже, форма `.gsfx`).
 * - value_labor / value_machines / value_machinists_wage / value_materials: составляющие
 *   коэффициента по видам ресурсов (форма `.gsfx`, SR-06) — оплата труда/эксплуатация машин/
 *   зарплата машинистов/материалы; в форме книг не заполняются (там один скаляр `value`).
 * - level: уровень применения коэффициента (форма `.gsfx`), если указан.
 */
final class RowModifier extends Data
{
    public function __construct(
        public string $name,
        public ?ModifierKind $kind = null,
        public ?string $code = null,
        public ?string $value = null,
        public ?string $value_labor = null,
        public ?string $value_machines = null,
        public ?string $value_machinists_wage = null,
        public ?string $value_materials = null,
        public ?int $level = null,
    ) {}
}
