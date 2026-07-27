<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Трудозатраты машинистов по методике 2022 г. из `Data.xml` (P3.9, `<Tzm2022>`, вложен ВНУТРЬ
 * ресурсного тега — на эталоне только `Mch`, 81 из 95, но привязка разбора к одному тегу породила
 * бы новую тихую потерю на другом файле). `quantity` тождественно `1` (81/81 на эталоне) — это
 * ставка по разряду, а не часы; прямой `Tzm` (переменные чел.-ч.) сосуществует ОТДЕЛЬНОЙ записью
 * `resources` на тех же 39 позициях. Толкование — что из `Tzm`/`Tzm2022` даёт `labor_hours_machinists`
 * — работа маппера P5.1, ридер обе формы не агрегирует (research/16-gsfx-reader-reopen.md §2 п.1).
 */
final class GsfxTzm2022 extends Data
{
    public function __construct(
        public ?string $work_class = null,
        public ?string $quantity = null,
        public ?string $price = null,
    ) {}
}
