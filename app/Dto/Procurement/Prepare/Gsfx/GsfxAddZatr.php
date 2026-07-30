<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Начисление сметы из `Data.xml` (P3.3, `<AddZatrats><AddZatrGlava><AddZatr>`) — временные
 * здания, зимнее удорожание, непредвиденные затраты, НДС и т.п.
 * - caption: наименование начисления.
 * - value: величина (обычно процент), decimal-строка, если задана напрямую.
 * - formula: формула начисления (`Formula`), если величина не задана напрямую, а вычисляется.
 * - options: сырые флаги ГрандСметы (`Options`, напр. `Percent InSmr HideZero`) — переносятся как
 *   есть, семантика отдельных флагов не документирована фазой и не додумывается здесь.
 */
final class GsfxAddZatr extends Data
{
    public function __construct(
        public string $caption,
        public ?string $value = null,
        public ?string $formula = null,
        public ?string $options = null,
    ) {}
}
