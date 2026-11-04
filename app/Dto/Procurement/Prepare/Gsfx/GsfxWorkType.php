<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Вид работ из каталога `Data.xml` (`<VidRab_Catalog><VidRab_Group><Vid_Rab>`) — носитель
 * нормативов НР/СП по виду работ («основной вид работ, у них есть подэлементы»).
 * - caption: название вида работ.
 * - id: внутренний ID программы (`ID`).
 * - category: категория (`Category`, напр. `Construction`).
 * - nr_code / sp_code: ссылки на приказы, которыми утверждены нормативы НР/СП (`NrCode`/`SpCode`)
 *   — переносятся как есть.
 * - nacl / nacl_curr / plan / plan_curr: ставки НР/СП, базисный и текущий уровень (`@Nacl`/
 *   `@NaclCurr`/`@Plan`/`@PlanCurr`) — носитель `EstimateWorkType::$overhead_rate`/
 *   `$overhead_rate_curr`/`$profit_rate`/`$profit_rate_curr` (SR-08). Маски (`NaclMask`/`PlanMask`)
 *   и шифры КФС (`NrKfsCode`/`NKB`/`PNB`/…) носителя в `EstimateWorkType` не имеют — остаются
 *   видимы атрибутным тальём `unmapped`, «про запас» не заводятся.
 */
final class GsfxWorkType extends Data
{
    public function __construct(
        public string $caption,
        public ?string $id = null,
        public ?string $category = null,
        public ?string $nr_code = null,
        public ?string $sp_code = null,
        public ?string $nacl = null,
        public ?string $nacl_curr = null,
        public ?string $plan = null,
        public ?string $plan_curr = null,
    ) {}
}
