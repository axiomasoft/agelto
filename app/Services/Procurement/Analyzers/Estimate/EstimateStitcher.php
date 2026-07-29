<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Ai\Estimate\EstimateRow;
use App\Dto\Procurement\Ai\Estimate\IncompleteRow;
use App\Enums\Procurement\IncompleteReason;
use App\Enums\Procurement\RowType;

/**
 * Детерминированный (без LLM) merge построчных чанков сметы (`docs/specs/v2/
 * 08-estimate-extraction.md` §4.2 «Stitcher», Scope Included P6.2). Чистая функция над DTO
 * (Implementation Rules P6.2) — никакого состояния/БД, обслуживает ОБА пути извлечения
 * (row-range D3/§4.1, оконный D30/§4.2 — P6.4) одинаково: вход — уже накопленный плоский список
 * строк/буфера одной сметы (`analysis.result.estimates[].{rows,incomplete_items}`, P6.1), выход —
 * `StitchReport`.
 */
final readonly class EstimateStitcher
{
    /**
     * @param  list<EstimateRow>  $rows  строки одной сметы, накопленные по всем чанкам подряд
     * @param  list<IncompleteRow>  $incompleteItems  буфер незавершённых строк всех чанков, в
     *                                                хронологическом порядке накопления (P6.1)
     */
    public function stitch(array $rows, array $incompleteItems): StitchReport
    {
        [$dedupedRows, $droppedDuplicateRowIndexes] = $this->dedupByRowIndex($rows);

        usort($dedupedRows, static fn (EstimateRow $a, EstimateRow $b): int => $a->row_index <=> $b->row_index);

        [$dedupedRows, $droppedDuplicateComposites] = $this->dedupAdjacentComposite($dedupedRows);

        [$sectionPaths, $sectionTitleMismatches] = $this->restoreHierarchy($dedupedRows);

        [$mergedIncompleteItems, $unmatchedIncompleteItems] = $this->stitchIncompleteItems($incompleteItems);

        return new StitchReport(
            rows: $dedupedRows,
            sectionPaths: $sectionPaths,
            droppedDuplicateRowIndexes: $droppedDuplicateRowIndexes,
            droppedDuplicateComposites: $droppedDuplicateComposites,
            sectionTitleMismatches: $sectionTitleMismatches,
            mergedIncompleteItems: $mergedIncompleteItems,
            unmatchedIncompleteItems: $unmatchedIncompleteItems,
        );
    }

    /**
     * Точный дубль row_index (напр. точечный re-ask вернул тот же диапазон повторно) — оставляем
     * ПЕРВОЕ по порядку появления вхождение (первый ответ модели на этот диапазон), остальные —
     * в отчёт (Code Guidance: не отбрасывать молча).
     *
     * @param  list<EstimateRow>  $rows
     * @return array{0: list<EstimateRow>, 1: list<int>}
     */
    private function dedupByRowIndex(array $rows): array
    {
        $seen = [];
        $kept = [];
        $dropped = [];

        foreach ($rows as $row) {
            if (array_key_exists($row->row_index, $seen)) {
                $dropped[] = $row->row_index;

                continue;
            }

            $seen[$row->row_index] = true;
            $kept[] = $row;
        }

        return [$kept, $dropped];
    }

    /**
     * Overlap-дедуп по составному ключу (code+name+quantity+total_price_curr) на СОСЕДНИХ позициях
     * после сортировки (research/E §5 «dedup по составному ключу на швах»). Флат-вход не несёт
     * границ чанков (P6.1 накапливает rows одним списком) — сравнение идёт по ВСЕМ соседним
     * парам, не только по фактическим швам (Known Deviation, задокументировано в StitchReport).
     * Действует только на row_type=Item (заголовки/subtotal намеренно исключены — совпадение
     * названия раздела не признак дубля).
     *
     * @param  list<EstimateRow>  $rows  уже отсортированы по row_index
     * @return array{0: list<EstimateRow>, 1: list<int>}
     */
    private function dedupAdjacentComposite(array $rows): array
    {
        $kept = [];
        $dropped = [];
        $previous = null;

        foreach ($rows as $row) {
            if ($previous !== null
                && $row->row_type === RowType::Item
                && $previous->row_type === RowType::Item
                && $this->compositeKey($row) === $this->compositeKey($previous)
            ) {
                $dropped[] = $row->row_index;

                continue;
            }

            $kept[] = $row;
            $previous = $row;
        }

        return [$kept, $dropped];
    }

    private function compositeKey(EstimateRow $row): string
    {
        return implode('|', [$row->code, $row->name, $row->quantity, $row->total_price_curr]);
    }

    /**
     * Восстанавливает иерархию раздел→подраздел (D10) стек-машиной по row_type: SectionHeader
     * открывает раздел, SubsectionHeader — подраздел внутри текущего раздела, Item/Subtotal
     * наследуют текущий путь. Кросс-проверка: если модель сама заявила `section_title` на строке
     * и он расходится с восстановленным путём — не фатально, попадает в sectionTitleMismatches
     * (два раздела с одинаковым именем различаются позицией в стеке, не именем — Code Guidance).
     *
     * @param  list<EstimateRow>  $rows
     * @return array{0: array<int, list<string>>, 1: list<int>}
     */
    private function restoreHierarchy(array $rows): array
    {
        $paths = [];
        $mismatches = [];
        $currentSection = null;
        $currentSubsection = null;

        foreach ($rows as $row) {
            $path = match ($row->row_type) {
                RowType::SectionHeader => [$row->name],
                RowType::SubsectionHeader => array_values(array_filter([$currentSection, $row->name])),
                default => array_values(array_filter([$currentSection, $currentSubsection])),
            };

            if ($row->row_type === RowType::SectionHeader) {
                $currentSection = $row->name;
                $currentSubsection = null;
            } elseif ($row->row_type === RowType::SubsectionHeader) {
                $currentSubsection = $row->name;
            }

            $paths[$row->row_index] = $path;

            if ($row->section_title !== null
                && ! in_array($row->row_type, [RowType::SectionHeader, RowType::SubsectionHeader], strict: true)
                && $path !== []
                && end($path) !== $row->section_title
            ) {
                $mismatches[] = $row->row_index;
            }
        }

        return [$paths, $mismatches];
    }

    /**
     * Сопоставляет split_by_page_end (конец чанка/страницы, оборванный код+частичные цифры) со
     * следующим по порядку orphan_numbers_at_page_start (голые цифры без кода, начало следующего
     * чанка/страницы) — §4б. Совпадение — по last_known_code (сильный сигнал) либо по
     * последовательным page_number (оконный путь, page_number обязателен). Без совпадения —
     * НЕ склеиваем: обе половины остаются как аномалия (консервативная склейка, Implementation
     * Rules P6.2). row_index для склеенной строки НЕ вычисляется здесь (IncompleteRow его не
     * несёт) — точная позиция в последовательности строк отложена на P6.3 (Known Deviation).
     *
     * @param  list<IncompleteRow>  $incompleteItems  в хронологическом порядке накопления
     * @return array{0: list<array{code: ?string, name: string, reason: string}>, 1: list<IncompleteRow>}
     */
    private function stitchIncompleteItems(array $incompleteItems): array
    {
        $matchedIndexes = [];
        $merged = [];

        foreach ($incompleteItems as $i => $split) {
            if ($split->reason !== IncompleteReason::SplitByPageEnd || in_array($i, $matchedIndexes, strict: true)) {
                continue;
            }

            foreach ($incompleteItems as $j => $orphan) {
                if ($j <= $i || in_array($j, $matchedIndexes, strict: true)) {
                    continue;
                }

                if ($orphan->reason !== IncompleteReason::OrphanNumbersAtPageStart) {
                    continue;
                }

                if (! $this->incompleteItemsMatch($split, $orphan)) {
                    continue;
                }

                $matchedIndexes[] = $i;
                $matchedIndexes[] = $j;

                $merged[] = [
                    'code' => $split->last_known_code ?? $orphan->last_known_code,
                    'name' => trim($split->raw_text.' '.$orphan->raw_text),
                    'reason' => 'merged_split_orphan',
                ];

                continue 2;
            }
        }

        $unmatched = [];

        foreach ($incompleteItems as $i => $item) {
            if (! in_array($i, $matchedIndexes, strict: true)) {
                $unmatched[] = $item;
            }
        }

        return [$merged, $unmatched];
    }

    private function incompleteItemsMatch(IncompleteRow $split, IncompleteRow $orphan): bool
    {
        if ($split->last_known_code !== null && $split->last_known_code === $orphan->last_known_code) {
            return true;
        }

        return $split->page_number !== null
            && $orphan->page_number !== null
            && $orphan->page_number === $split->page_number + 1;
    }
}
