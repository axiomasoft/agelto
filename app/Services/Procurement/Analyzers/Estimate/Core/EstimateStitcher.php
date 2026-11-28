<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate\Core;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\IncompleteRow;
use App\Dto\Procurement\Analyzers\Estimate\StitchReport;
use App\Enums\Procurement\Estimate\IncompleteReason;
use App\Enums\Procurement\Estimate\RowType;

/**
 * Детерминированный (без LLM) merge построчных чанков сметы (`docs/specs/v2/
 * 08-estimate-extraction.md` §4.2 «Stitcher»). Чистая функция над DTO
 * — никакого состояния/БД, обслуживает ОБА пути извлечения
 * (row-range и оконный) одинаково: вход — уже накопленный плоский список
 * строк/буфера одной сметы (`analysis.result.estimates[].{rows,incomplete_items}`), выход —
 * `StitchReport`.
 */
final readonly class EstimateStitcher
{
    /**
     * @param  list<EstimateRow>  $rows  строки одной сметы, накопленные по всем чанкам подряд
     * @param  list<IncompleteRow>  $incompleteItems  буфер незавершённых строк всех чанков, в
     *                                                хронологическом порядке накопления
     */
    public function stitch(array $rows, array $incompleteItems): StitchReport
    {
        [$dedupedRows, $droppedDuplicateRowIndexes] = $this->dedupByRowIndex($rows);

        usort($dedupedRows, static fn (EstimateRow $a, EstimateRow $b): int => $a->row_index <=> $b->row_index);

        [$sectionPaths, $sectionTitleMismatches] = $this->restoreHierarchy($dedupedRows);

        [$mergedIncompleteItems, $unmatchedIncompleteItems] = $this->stitchIncompleteItems($incompleteItems);

        return new StitchReport(
            rows: $dedupedRows,
            sectionPaths: $sectionPaths,
            droppedDuplicateRowIndexes: $droppedDuplicateRowIndexes,
            droppedDuplicateComposites: [],
            sectionTitleMismatches: $sectionTitleMismatches,
            mergedIncompleteItems: $mergedIncompleteItems,
            unmatchedIncompleteItems: $unmatchedIncompleteItems,
        );
    }

    /**
     * Точный дубль row_index (напр. точечный re-ask вернул тот же диапазон повторно) — оставляем
     * ПЕРВОЕ по порядку появления вхождение (первый ответ модели на этот диапазон), остальные —
     * в отчёт, чтобы дубли не отбрасывались молча.
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
     * Восстанавливает иерархию раздел→подраздел стек-машиной по row_type: SectionHeader
     * открывает раздел, SubsectionHeader — подраздел внутри текущего раздела, Item/Subtotal
     * наследуют текущий путь. Кросс-проверка: если модель сама заявила `section_title` на строке
     * и он расходится с восстановленным путём — не фатально, попадает в sectionTitleMismatches
     * (два раздела с одинаковым именем различаются позицией в стеке, не именем).
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
                RowType::SubsectionHeader => [
                    ...($currentSection !== null && $currentSection !== '' ? [$currentSection] : []),
                    ...($row->name !== '' ? [$row->name] : []),
                ],
                default => [
                    ...($currentSection !== null && $currentSection !== '' ? [$currentSection] : []),
                    ...($currentSubsection !== null && $currentSubsection !== '' ? [$currentSubsection] : []),
                ],
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
     * НЕ склеиваем: обе половины остаются как аномалия (консервативная склейка). row_index для склеенной строки НЕ вычисляется здесь (IncompleteRow его не
     * несёт) — точная позиция требует отдельного сопоставления или операторского ревью.
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
