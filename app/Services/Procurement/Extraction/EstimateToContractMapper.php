<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\Estimate\EstimateRow;
use App\Dto\Procurement\Ai\Estimate\PassportResponse;
use App\Dto\Procurement\Ai\Estimate\RowModifier;
use App\Dto\Procurement\Ai\Estimate\RowResource;
use App\Dto\Procurement\Ai\Estimate\TotalLine;
use App\Enums\Procurement\RowType;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Resource;
use App\Models\Procurement\Estimate\Section;
use App\Models\Procurement\Estimate\Total;
use Illuminate\Support\Collection;

/**
 * Обратный маппер `prc_estimate_*` (нормализованное хранилище, P6.3) → DTO-контракт P2.2
 * (`PassportResponse` + `rows: EstimateRow[]`) — та же форма, что `analysis.result.estimates[]`
 * и `<name>.gold.json` (docs/specs/v2/08-estimate-extraction.md §8.2). Единственный потребитель —
 * `GoldExampleRecorder` (P9.3): без этого маппера gold-снапшот собрать нечем (ревью K п.23, P6.3
 * делал только DTO→таблицы). Собирает данные из НОРМАЛИЗОВАННЫХ таблиц, НЕ из
 * `Estimate.raw_result`/`raw_result_ref` (Implementation Rules P9.3) — датасет учит целевому
 * контракту, а не сырому выводу модели.
 *
 * ОГРАНИЧЕНИЕ реконструкции (структурное свойство схемы, не баг): `row_index` заголовков разделов/
 * подразделов НЕ персистируется нигде (`prc_estimate_sections` хранит только `position` среди
 * siblings, D10) — при реконструкции `row_index` ВСЕХ строк (включая синтезированные заголовки)
 * переприсваивается ПОСЛЕДОВАТЕЛЬНО заново по порядку обхода позиций (канон §4а — сквозная
 * нумерация — соблюдён, просто не те же числа, что были в исходном сыром выводе модели). Строки
 * `row_type=subtotal` вообще не реконструируются — они не имеют собственной персистентной записи
 * (D10, `EstimateValidator` использовал их только для арифметики на лету).
 */
final readonly class EstimateToContractMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toContract(Estimate $estimate): array
    {
        $estimate->loadMissing(['sections', 'items.resources', 'totals']);

        $passport = PassportResponse::from([
            'passport' => $estimate->passport?->toArray() ?? [],
            'estimate_number' => $estimate->estimate_number,
            'currency' => $estimate->currency,
            'base_year' => $estimate->base_year,
            'price_level' => $estimate->price_level,
            'rows_expected' => $estimate->rows_expected ?? 0,
            'totals' => ['footer' => $this->totalsTree($estimate->totals)],
        ]);

        return [
            ...$passport->toArray(),
            'ordinal' => $estimate->ordinal,
            'rows' => array_map(
                static fn (EstimateRow $row): array => $row->toArray(),
                $this->rows($estimate),
            ),
        ];
    }

    /**
     * @param  Collection<int, Total>  $totals
     * @return list<TotalLine>
     */
    private function totalsTree(Collection $totals, ?int $parentId = null): array
    {
        return $totals
            ->where('parent_id', $parentId)
            ->sortBy('position')
            ->map(fn (Total $total): TotalLine => new TotalLine(
                label: $total->label,
                value: (string) $total->amount,
                kind: $total->kind,
                children: $this->totalsTree($totals, $total->id),
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<EstimateRow>
     */
    private function rows(Estimate $estimate): array
    {
        /** @var Collection<int, Section> $sectionsById */
        $sectionsById = $estimate->sections->keyBy('id');
        $items = $estimate->items->sortBy('row_index')->values();

        $rows = [];
        $rowIndex = 0;
        $previousBreadcrumb = [];

        /** @var Item $item */
        foreach ($items as $item) {
            $breadcrumb = $item->section_id === null ? [] : $this->breadcrumb($sectionsById, $item->section_id);
            $commonLength = $this->commonPrefixLength($previousBreadcrumb, $breadcrumb);

            foreach (array_slice($breadcrumb, $commonLength) as $offset => $title) {
                $depth = $commonLength + $offset;

                $rows[] = new EstimateRow(
                    row_index: $rowIndex++,
                    row_type: $depth === 0 ? RowType::SectionHeader : RowType::SubsectionHeader,
                    name: $title,
                    section_title: $title,
                );
            }

            $rows[] = new EstimateRow(
                row_index: $rowIndex++,
                row_type: RowType::Item,
                name: $item->name,
                section_title: $breadcrumb === [] ? null : $breadcrumb[array_key_last($breadcrumb)],
                code: $item->code,
                unit: $item->unit,
                quantity: $item->quantity === null ? null : (string) $item->quantity,
                unit_price: $item->unit_price === null ? null : (string) $item->unit_price,
                total_price: $item->total_price === null ? null : (string) $item->total_price,
                resources: $item->resources
                    ->map(fn (Resource $resource): RowResource => new RowResource(
                        resource_type: $resource->resource_type,
                        name: $resource->name,
                        code: $resource->code,
                        unit: $resource->unit,
                        quantity: $resource->quantity === null ? null : (string) $resource->quantity,
                        price: $resource->price === null ? null : (string) $resource->price,
                    ))
                    ->all(),
                modifiers: $this->modifiers($item),
            );

            $previousBreadcrumb = $breadcrumb;
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Section>  $sectionsById
     * @return list<string>
     */
    private function breadcrumb(Collection $sectionsById, int $sectionId): array
    {
        $path = [];
        $current = $sectionsById->get($sectionId);

        while ($current !== null) {
            array_unshift($path, $current->title);
            $current = $current->parent_id === null ? null : $sectionsById->get($current->parent_id);
        }

        return $path;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function commonPrefixLength(array $a, array $b): int
    {
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * @return list<RowModifier>
     */
    private function modifiers(Item $item): array
    {
        $modifiers = (array) ($item->attributes?->get('modifiers') ?? []);

        return array_map(static fn (array $modifier): RowModifier => RowModifier::from($modifier), $modifiers);
    }
}
