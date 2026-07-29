<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts\Dto;

/**
 * Сводка результата анализа, которую витрина показывает НЕ ЗНАЯ про предметные таблицы типа
 * (§3 контракта P2.1, D27): анализатор отдаёт данные, презентацию делает P5.
 *
 * Вложенности в `$metrics` нет намеренно — иначе витрина начнёт разбирать структуру и снова
 * узнает про предмет. `$detailRoute` — ИМЯ маршрута и его параметры, а не готовый URL: генерацию
 * ссылки делает презентационный слой; `null` — предметного экрана у типа нет.
 *
 * Возврат HTML/Blade/Filament-компонентов ЗАПРЕЩЁН контрактом.
 */
final readonly class AnalysisSummary
{
    /**
     * @param  list<SummaryMetric>  $metrics
     * @param  array<string, mixed>|null  $detailRouteParameters
     */
    public function __construct(
        public string $title,
        public array $metrics,
        public ?string $detailRoute = null,
        public ?array $detailRouteParameters = null,
    ) {}
}
