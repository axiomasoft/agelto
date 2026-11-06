<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowAttributes;
use App\Dto\Procurement\Analyzers\Estimate\RowModifier;
use App\Dto\Procurement\Analyzers\Estimate\RowResource;
use App\Dto\Procurement\Analyzers\Estimate\TotalLine;
use App\Enums\Procurement\Estimate\RowType;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Resource;
use App\Models\Procurement\Estimate\Section;
use App\Models\Procurement\Estimate\Total;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * Обратный маппер `prc_estimate_*` (нормализованное хранилище) → DTO-контракт
 * (`PassportResponse` + `rows: EstimateRow[]`) — та же форма, что `analysis.result.estimates[]`
 * и `<name>.gold.json` (docs/specs/v2/08-estimate-extraction.md §8.2). Единственный потребитель —
 * `GoldExampleRecorder`: без этого маппера gold-снапшот собрать нечем ( делал только DTO→таблицы). Собирает данные из НОРМАЛИЗОВАННЫХ таблиц, НЕ из
 * `Estimate.raw_result`/`raw_result_ref` — датасет учит целевому
 * контракту, а не сырому выводу модели.
 *
 * ОГРАНИЧЕНИЕ реконструкции (структурное свойство схемы, не баг): `row_index` заголовков разделов/
 * подразделов НЕ персистируется нигде (`prc_estimate_sections` хранит только `position` среди
 * siblings) — при реконструкции `row_index` ВСЕХ строк (включая синтезированные заголовки)
 * переприсваивается ПОСЛЕДОВАТЕЛЬНО заново по порядку обхода позиций (канон §4а — сквозная
 * нумерация — соблюдён, просто не те же числа, что были в исходном сыром выводе модели). Строки
 * `row_type=subtotal` вообще не реконструируются — они не имеют собственной персистентной записи
 * (`EstimateValidator` использовал их только для арифметики на лету).
 */
final readonly class EstimateToContractMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toContract(Estimate $estimate): array
    {
        $estimate->loadMissing(['sections', 'items.resources', 'totals']);

        $passportData = $estimate->passport;
        $passport = PassportResponse::from([
            'passport' => $passportData instanceof Arrayable ? $passportData->toArray() : [],
            'estimate_number' => $estimate->estimate_number,
            'currency' => $estimate->currency,
            'base_year' => $estimate->base_year,
            'price_level' => $estimate->price_level,
            'rows_expected' => $estimate->rows_expected ?? 0,
            'totals' => ['footer' => $this->totalsTree($estimate->totals)],
            'calc_method' => $estimate->calc_method,
            'labor_hours_workers' => $estimate->labor_hours_workers === null ? null : (string) $estimate->labor_hours_workers,
            'labor_hours_machinists' => $estimate->labor_hours_machinists === null ? null : (string) $estimate->labor_hours_machinists,
            'document_number' => $estimate->document_number,
            'document_date' => $estimate->document_date?->toDateString(),
            'period_start' => $estimate->period_start?->toDateString(),
            'period_end' => $estimate->period_end?->toDateString(),
        ]);

        /** @var array<string, mixed> $passportPayload */
        $passportPayload = $passport->toArray();

        return [
            ...$passportPayload,
            'ordinal' => $estimate->ordinal,
            'rows' => array_map(
                static fn (EstimateRow $row): array => $row->toArray(),
                $this->rows($estimate),
            ),
        ];
    }

    /**
     * @param  Collection<int, Total>  $totals
     * @param  array<int, true>  $visited
     * @return list<TotalLine>
     */
    private function totalsTree(Collection $totals, ?int $parentId = null, array $visited = []): array
    {
        if ($parentId !== null && isset($visited[$parentId])) {
            return [];
        }

        $nextVisited = $parentId !== null ? $visited + [$parentId => true] : $visited;

        return array_values($totals
            ->where('parent_id', $parentId)
            ->reject(static fn (Total $total): bool => isset($nextVisited[$total->id]))
            ->sortBy('position')
            ->map(fn (Total $total): TotalLine => new TotalLine(
                label: $total->label,
                value: $total->amount === null ? null : (string) $total->amount,
                kind: $total->kind,
                chapter_number: $total->chapter_number,
                rate: $total->rate === null ? null : (string) $total->rate,
                children: $this->totalsTree($totals, $total->id, $nextVisited),
            ))
            ->values()
            ->all());
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

            $modifiers = $this->modifiers($item);

            $rows[] = new EstimateRow(
                row_index: $rowIndex++,
                row_type: RowType::Item,
                name: $item->name,
                section_title: $breadcrumb === [] ? null : $breadcrumb[array_key_last($breadcrumb)],
                code: $item->code,
                unit: $item->unit,
                quantity: $item->quantity === null ? null : (string) $item->quantity,
                unit_price_base: $item->unit_price_base === null ? null : (string) $item->unit_price_base,
                unit_price_curr: $item->unit_price_curr === null ? null : (string) $item->unit_price_curr,
                total_price_base: $item->total_price_base === null ? null : (string) $item->total_price_base,
                total_price_curr: $item->total_price_curr === null ? null : (string) $item->total_price_curr,
                index_code: $item->index_code,
                index_value: $item->index_value === null ? null : (string) $item->index_value,
                source_position_number: $item->source_position_number,
                resources: $item->resources
                    ->map(fn (Resource $resource): RowResource => new RowResource(
                        resource_type: $resource->resource_type,
                        name: $resource->name,
                        code: $resource->code,
                        unit: $resource->unit,
                        quantity: $resource->quantity === null ? null : (string) $resource->quantity,
                        price_base: $resource->price_base === null ? null : (string) $resource->price_base,
                        price_curr: $resource->price_curr === null ? null : (string) $resource->price_curr,
                        index_code: $resource->index_code,
                        index_value: $resource->index_value === null ? null : (string) $resource->index_value,
                    ))
                    ->all(),
                attributes: $modifiers === [] ? null : new RowAttributes(modifiers: $modifiers),
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
        $visited = [];
        $current = $sectionsById->get($sectionId);

        while ($current !== null) {
            if (isset($visited[$current->id])) {
                break;
            }

            $visited[$current->id] = true;
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
        $modifiers = $item->attributes['modifiers'] ?? [];

        if (! is_array($modifiers)) {
            return [];
        }

        $result = [];

        foreach ($modifiers as $modifier) {
            if (is_array($modifier)) {
                $result[] = RowModifier::from($modifier);
            }
        }

        return $result;
    }
}
