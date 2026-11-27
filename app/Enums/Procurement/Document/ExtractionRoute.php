<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

/**
 * Путь извлечения, выбранный preflight'ом: `Whole` — файл в лимитах активного маршрута,
 * `Windowed` — оконный путь для файла сверх лимитов. Значение не персистится напрямую.
 */
enum ExtractionRoute
{
    case Whole;
    case Windowed;
}
