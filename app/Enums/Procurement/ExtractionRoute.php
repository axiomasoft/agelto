<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Путь извлечения сметы, выбранный preflight'ом (`ExtractionPreflight`, D29): `Whole` — файл в
 * лимитах активного маршрута, row-range по целому кэшированному документу (D3, основной путь);
 * `Windowed` — сверх лимитов, трёхстадийный оконный путь (D30, P6.4, `WindowedChunkSource`).
 * Не backed-enum — значение нигде не персистится напрямую (решение живёт в `analysis.progress`
 * как факт выбора, не как это перечисление).
 */
enum ExtractionRoute
{
    case Whole;
    case Windowed;
}
