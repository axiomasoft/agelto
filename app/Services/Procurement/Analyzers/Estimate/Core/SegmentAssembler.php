<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate\Core;

use App\Dto\Procurement\Analyzers\Estimate\DiscoveryMarkerLine;
use App\Enums\Procurement\Estimate\DiscoveryMarker;

/**
 * Детерминированная (БЕЗ LLM) сборка сегментов смет из накопленных discovery-маркеров ( `docs/specs/v2/08-estimate-extraction.md` §4.2 Стадия 1). Вход — ПЛОСКИЙ
 * список маркеров ВСЕХ discovery-окон, В ПОРЯДКЕ ПРИХОДА ОКОН (окно 0 первым; порядок маркеров
 * внутри окна не важен — коллапс идёт по номеру физической страницы). 2 прохода:
 * (1) `collapseMarkers` схлопывает противоречивые маркеры с ОДНОЙ страницы, пришедшие из
 *     разных окон, в единственный «эффективный» маркер этой страницы: `estimate_start` — первое
 *     (самое раннее) окно побеждает; `estimate_end` — последнее (самое позднее) окно ВСЕГДА
 *     перезаписывает; `continuation` — первое окно побеждает (не затирает уже записанный
 *     start/end этой же страницы);
 * (2) детерминированный проход `page=1..totalPages` строит сегменты — ни одна страница не
 *     теряется и не приписывается наугад соседней: страница без маркера/с `continuation` при
 *     ЗАКРЫТОМ сегменте открывает НОВЫЙ, сразу помеченный `suspicious=true`, сегмент.
 */
final readonly class SegmentAssembler
{
    /**
     * @param  list<DiscoveryMarkerLine>  $markersInWindowArrivalOrder
     * @return list<array{ordinal: int, page_start: int, page_end: int, title: ?string, suspicious: bool}>
     */
    public function assemble(array $markersInWindowArrivalOrder, int $totalPages): array
    {
        [$effective, $titles] = $this->collapseMarkers($markersInWindowArrivalOrder);

        $segments = [];
        $current = null;

        for ($page = 1; $page <= $totalPages; $page++) {
            $marker = $effective[$page] ?? null;

            if ($marker === DiscoveryMarker::EstimateStart) {
                if ($current !== null) {
                    // Новая смета началась, а предыдущая не дождалась estimate_end — закрываем
                    // её принудительно на предыдущей странице, помечаем аномалией.
                    $segments[] = $this->close($current, $page - 1, forceSuspicious: true);
                }

                $current = ['page_start' => $page, 'title' => $titles[$page] ?? null, 'suspicious' => false];

                continue;
            }

            if ($marker === DiscoveryMarker::EstimateEnd) {
                if ($current === null) {
                    // estimate_end без предшествующего estimate_start — однострочный сегмент,
                    // аномалия, но страница не теряется.
                    $segments[] = $this->close(['page_start' => $page, 'title' => null, 'suspicious' => true], $page, forceSuspicious: true);

                    continue;
                }

                $segments[] = $this->close($current, $page, forceSuspicious: false);
                $current = null;

                continue;
            }

            // continuation ИЛИ отсутствие маркера на этой странице.
            if ($current === null) {
                // Страница вне какого-либо явно открытого сегмента — открываем НОВЫЙ, сразу
                // suspicious (не приписываем соседнему сегменту наугад).
                $current = ['page_start' => $page, 'title' => null, 'suspicious' => true];
            }
        }

        if ($current !== null) {
            // Документ закончился, а последний сегмент не дождался estimate_end.
            $segments[] = $this->close($current, $totalPages, forceSuspicious: true);
        }

        return array_map(
            static fn (array $segment, int $index): array => ['ordinal' => $index + 1, ...$segment],
            $segments,
            array_keys($segments),
        );
    }

    /**
     * @param  list<DiscoveryMarkerLine>  $markers
     * @return array{0: array<int, DiscoveryMarker>, 1: array<int, ?string>}
     */
    private function collapseMarkers(array $markers): array
    {
        $effective = [];
        $titles = [];

        foreach ($markers as $marker) {
            $page = $marker->page;

            if ($marker->marker === DiscoveryMarker::EstimateEnd) {
                $effective[$page] = DiscoveryMarker::EstimateEnd;

                continue;
            }

            if (! isset($effective[$page])) {
                $effective[$page] = $marker->marker;

                if ($marker->marker === DiscoveryMarker::EstimateStart) {
                    $titles[$page] = $marker->title;
                }
            }
        }

        return [$effective, $titles];
    }

    /**
     * @param  array{page_start: int, title: ?string, suspicious: bool}  $current
     * @return array{page_start: int, page_end: int, title: ?string, suspicious: bool}
     */
    private function close(array $current, int $pageEnd, bool $forceSuspicious): array
    {
        return [
            'page_start' => $current['page_start'],
            'page_end' => $pageEnd,
            'title' => $current['title'],
            'suspicious' => $forceSuspicious || $current['suspicious'],
        ];
    }
}
