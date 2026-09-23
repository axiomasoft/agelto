<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\IncompleteRow;

/**
 * Результат `EstimateStitcher::stitch()` (`docs/specs/v2/08-estimate-extraction.md` §4.2 «Stitcher»,
 * Code Guidance P6.2: «не чинить данные молча — каждый нетривиальный шаг в StitchReport»).
 * Чистый value-объект — никакой персистентности (P6.3 читает это как источник для записи).
 *
 * - rows: финальный дедуплицированный, отсортированный по row_index список строк.
 * - sectionPaths: восстановленная иерархия — row_index => breadcrumb названий разделов/подразделов
 *   (D10: собрана на бэкенде из row_type+section_title, LLM дерево не формирует).
 * - droppedDuplicateRowIndexes: row_index строк, отброшенных как точный дубль (тот же row_index
 *   встретился повторно — оставлена первая по порядку появления).
 * - droppedDuplicateComposites: legacy-поле, всегда пусто. Разные row_index сохраняются:
 *   совпадение содержимого без границ чанков не доказывает дублирование.
 * - sectionTitleMismatches: row_index строк, где заявленный моделью `section_title` разошёлся с
 *   иерархией, восстановленной по `row_type`-стеку — не фатально, сигнал для операторского ревью.
 * - mergedIncompleteItems: пары incomplete_items (split_by_page_end + orphan_numbers_at_page_start),
 *   успешно сопоставленные по last_known_code/page_number (§4б) — БЕЗ вычисленного row_index
 *   (IncompleteRow не несёт row_index — Known Deviation, точное позиционирование в
 *   последовательности строк отложено на P6.3/операторское ревью).
 * - unmatchedIncompleteItems: incomplete_items, для которых пара не найдена — аномалия сама по
 *   себе (обрыв без продолжения в соседнем чанке/окне).
 */
final readonly class StitchReport
{
    /**
     * @param  list<EstimateRow>  $rows
     * @param  array<int, list<string>>  $sectionPaths
     * @param  list<int>  $droppedDuplicateRowIndexes
     * @param  list<int>  $droppedDuplicateComposites
     * @param  list<int>  $sectionTitleMismatches
     * @param  list<array{code: ?string, name: string, reason: string}>  $mergedIncompleteItems
     * @param  list<IncompleteRow>  $unmatchedIncompleteItems
     */
    public function __construct(
        public array $rows = [],
        public array $sectionPaths = [],
        public array $droppedDuplicateRowIndexes = [],
        public array $droppedDuplicateComposites = [],
        public array $sectionTitleMismatches = [],
        public array $mergedIncompleteItems = [],
        public array $unmatchedIncompleteItems = [],
    ) {}

    public function hasAnomalies(): bool
    {
        return $this->droppedDuplicateRowIndexes !== []
            || $this->droppedDuplicateComposites !== []
            || $this->sectionTitleMismatches !== []
            || $this->unmatchedIncompleteItems !== []
            || $this->mergedIncompleteItems !== [];
    }
}
