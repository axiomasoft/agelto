<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Группа видов работ каталога `Data.xml` (P3.3, `<VidRab_Group>`).
 */
final class GsfxWorkTypeGroup extends Data
{
    /**
     * @param  list<GsfxWorkType>  $work_types
     */
    public function __construct(
        public string $caption,
        public ?string $id = null,
        public array $work_types = [],
    ) {}
}
