<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler\Contracts;

/**
 * Узкий шов для площадко-агностичного `ArchiveWindowScanner`: фильтр профиля,
 * несущий ценовую границу, отдаёт готовый стартовый интервал стека дробления — без
 * `instanceof <площадка>SearchFilter` внутри сканера. Один реализующий класс сегодня
 * (`EisSearchFilter`) — гипотетический шов, поэтому интерфейс узкий, без задела.
 */
interface HasPriceRange
{
    /**
     * Форма `"{from}:{to}"`; `null` — верхняя граница не заполнена, сканер падает обратно на
     * `platform listing price bound`.
     */
    public function startingPriceInterval(): ?string;
}
