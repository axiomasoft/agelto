<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Dto\Procurement\Ai\Estimate\TotalLine;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Section;
use App\Models\Procurement\Estimate\Total;

/**
 * Единственная точка персистентности `prc_estimates`/`prc_estimate_sections`/
 * `prc_estimate_items`/`prc_estimate_resources`/`prc_estimate_totals` (P6.3, аналог
 * `DocumentStoreRepository`) — `EstimatePersister`/`RerunRangeAction` решают, какие атрибуты
 * писать и когда, репозиторий только читает/пишет по уже принятым ключам.
 */
final readonly class EstimateStoreRepository
{
    /**
     * Идемпотентный upsert по `(analysis_id, ordinal)` (unique, P1.4/D12 — несколько смет на
     * один анализ) — повторный persist того же анализа обновляет, не дублирует.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertEstimate(int $analysisId, int $ordinal, array $attributes): Estimate
    {
        return Estimate::query()->updateOrCreate(
            ['analysis_id' => $analysisId, 'ordinal' => $ordinal],
            $attributes,
        );
    }

    /**
     * Стабильный (без удаления) резолв раздела по `(estimate_id, parent_id, title)` — идемпотентно
     * между повторными persist одного и того же анализа: сохраняет `Section.id` (Items ссылаются
     * на него `nullOnDelete`), не плодит дублей при том же названии на том же уровне иерархии.
     */
    public function findOrCreateSection(int $estimateId, ?int $parentId, string $title, int $position, ?string $sectionNumber): Section
    {
        $section = Section::query()->firstOrCreate(
            ['estimate_id' => $estimateId, 'parent_id' => $parentId, 'title' => $title],
            ['position' => $position, 'section_number' => $sectionNumber],
        );

        if ($section->position !== $position || $section->section_number !== $sectionNumber) {
            $section->update(['position' => $position, 'section_number' => $sectionNumber]);
        }

        return $section;
    }

    /**
     * Точечный upsert позиции по `(estimate_id, row_index)` (unique) — полный persist И
     * `RerunRangeAction` используют один и тот же путь замены диапазона (Implementation Rules
     * P6.3: «deleteWhere+insert для замены диапазона недопустим — точечный upsert по row_index»).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertItem(int $estimateId, ?int $sectionId, int $rowIndex, array $attributes): Item
    {
        return Item::query()->updateOrCreate(
            ['estimate_id' => $estimateId, 'row_index' => $rowIndex],
            ['section_id' => $sectionId, ...$attributes],
        );
    }

    /**
     * Ресурсы позиции не несут собственного стабильного ключа вне `item_id` (P2.2 `RowResource`)
     * — полная замена набора на каждый persist позиции (НЕ диапазон строк, поэтому не подпадает
     * под запрет «deleteWhere+insert» из Implementation Rules — тот запрет про row_index-диапазон).
     *
     * @param  list<array<string, mixed>>  $resourceRows
     */
    public function replaceResources(Item $item, array $resourceRows): void
    {
        $item->resources()->delete();

        if ($resourceRows !== []) {
            $item->resources()->createMany($resourceRows);
        }
    }

    /**
     * Дерево итогов не несёт FK-зависимых записей извне (`prc_estimate_totals` — только
     * self-referencing `parent_id`) — полная пересборка на каждый persist безопасна.
     *
     * @param  list<TotalLine>  $footer
     */
    public function replaceTotals(int $estimateId, array $footer): void
    {
        Total::query()->where('estimate_id', $estimateId)->delete();

        $this->createTotalTree($estimateId, null, $footer);
    }

    /**
     * @param  list<TotalLine>  $lines
     */
    private function createTotalTree(int $estimateId, ?int $parentId, array $lines): void
    {
        $position = 1;

        foreach ($lines as $line) {
            $total = Total::query()->create([
                'estimate_id' => $estimateId,
                'parent_id' => $parentId,
                'position' => $position++,
                'label' => $line->label,
                'kind' => $line->kind,
                'amount' => $line->value,
            ]);

            if ($line->children !== []) {
                $this->createTotalTree($estimateId, $total->id, $line->children);
            }
        }
    }
}
