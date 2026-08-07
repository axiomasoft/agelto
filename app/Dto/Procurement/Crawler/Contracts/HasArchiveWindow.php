<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler\Contracts;

use Carbon\CarbonImmutable;

/**
 * Узкий шов для `StartCrawlerRunAction` (P3.8, D113): фильтр профиля, несущий окно
 * archive-прогона, отдаёт его границы — без `instanceof <площадка>SearchFilter` внутри Action.
 * Один реализующий класс сегодня (`EisSearchFilter`) — гипотетический шов, поэтому интерфейс
 * узкий, без задела.
 */
interface HasArchiveWindow
{
    /**
     * Начало окна archive-прогона; `null` — окно не заполнено (профиль резолвится в отказ,
     * если явный `--from`/`--to` тоже отсутствует).
     */
    public function archiveWindowFrom(): ?CarbonImmutable;

    /**
     * Конец окна archive-прогона; та же оговорка, что у `archiveWindowFrom()`.
     */
    public function archiveWindowTo(): ?CarbonImmutable;
}
