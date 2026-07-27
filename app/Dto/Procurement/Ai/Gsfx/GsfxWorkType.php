<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Вид работ из каталога `Data.xml` (P3.3, `<VidRab_Catalog><VidRab_Group><Vid_Rab>`) — носитель
 * нормативов НР/СП по виду работ (Phase Context P3, «основной вид работ, у них есть подэлементы»).
 * - caption: название вида работ.
 * - id: внутренний ID программы (`ID`).
 * - category: категория (`Category`, напр. `Construction`).
 * - nr_code / sp_code: ссылки на приказы, которыми утверждены нормативы НР/СП (`NrCode`/`SpCode`)
 *   — переносятся как есть, дальнейшие атрибуты (`Nacl`/`Plan`/маски/коэффициенты пересчёта) не
 *   документированы фазой и не додумываются здесь — вход в `P4`/`P5` по факту, если понадобится.
 */
final class GsfxWorkType extends Data
{
    public function __construct(
        public string $caption,
        public ?string $id = null,
        public ?string $category = null,
        public ?string $nr_code = null,
        public ?string $sp_code = null,
    ) {}
}
