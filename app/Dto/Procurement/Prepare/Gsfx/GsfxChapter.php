<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Раздел сметы из `Data.xml` (P3.3, `<Chapters><Chapter>`) — плоский список позиций, вложенных
 * подразделов `Data.xml` реального корпуса не несёт (разведка проверена P3.3, `Chapter` внутри
 * `Chapter` не встречается; встретится — попадёт в `GsfxDocument::$unmapped`, не в это дерево).
 * - caption: название раздела.
 * - sys_id: внутренний ID программы (`SysID`).
 */
final class GsfxChapter extends Data
{
    /**
     * @param  list<GsfxPosition>  $positions
     */
    public function __construct(
        public string $caption,
        public ?string $sys_id = null,
        public array $positions = [],
    ) {}
}
