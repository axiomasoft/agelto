<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Gold;

use App\Enums\Procurement\Estimate\ResourceType;
use Spatie\LaravelData\Data;

/**
 * Маркер группы ресурсов позиции — `estimates/gold/<CODE>.profile.json`
 * §`row_rules.resource_groups`. Строка листа, чей текст колонки `name` совпадает С `label`
 * ТОЧНО (не префиксом), в gold не попадает — она переключает `resource_type` последующих строк
 * `SheetGoldMapper` (напечатанная сумма маркера — агрегат детей, перенос удвоил бы величину).
 * label: дословный текст маркера («ОТ(ЗТ)», «ЭМ», «М»).
 * resource_type: тип ресурса, который эта группа включает для последующих строк.
 */
final class GoldResourceGroup extends Data
{
    public function __construct(
        public string $label,
        public ResourceType $resource_type,
    ) {}
}
