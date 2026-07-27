<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Группа видов работ каталога `Data.xml` (P3.3, `<VidRab_Group>`).
 * - catalog_type: тип каталога-владельца (`Vids_Rab@Type`, P3.9, напр. «Виды работ 2001г») — по
 *   нему различается каталог, на который ссылается `Position@Vr2001` (`research/16-gsfx-reader-reopen.md`
 *   §1); блоков `VidRab_Catalog/Vids_Rab` может быть НЕСКОЛЬКО, обходятся ВСЕ.
 */
final class GsfxWorkTypeGroup extends Data
{
    /**
     * @param  list<GsfxWorkType>  $work_types
     */
    public function __construct(
        public string $caption,
        public ?string $id = null,
        public ?string $catalog_type = null,
        public array $work_types = [],
    ) {}
}
