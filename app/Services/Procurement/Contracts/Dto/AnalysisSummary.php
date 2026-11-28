<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts\Dto;

/**
 * Данные сводки анализа для витрины, которая не знает предметные таблицы типа.
 * Metrics — плоский набор. DetailRoute содержит имя маршрута и параметры; URL строит
 * презентационный слой. Null означает отсутствие предметного экрана.
 * HTML, Blade и Filament components в результате не передаются.
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
