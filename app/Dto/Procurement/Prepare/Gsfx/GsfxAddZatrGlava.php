<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Глава начислений сметы из `Data.xml` (P3.3, `<AddZatrats><AddZatrGlava>`) — группа начислений
 * одного вида (`Glava` — номер главы по методике накладных расходов).
 */
final class GsfxAddZatrGlava extends Data
{
    /**
     * @param  list<GsfxAddZatr>  $items
     */
    public function __construct(
        public ?string $glava = null,
        public array $items = [],
    ) {}
}
