<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\EstimateTotalsBlock;
use App\Dto\Procurement\Analyzers\Estimate\RowAttributes;
use App\Dto\Procurement\Analyzers\Estimate\RowModifier;
use App\Dto\Procurement\Analyzers\Estimate\RowResource;
use App\Dto\Procurement\Analyzers\Estimate\TotalLine;
use App\Dto\Procurement\Prepare\Gsfx\GsfxAddZatr;
use App\Dto\Procurement\Prepare\Gsfx\GsfxAddZatrGlava;
use App\Dto\Procurement\Prepare\Gsfx\GsfxCoefficient;
use App\Dto\Procurement\Prepare\Gsfx\GsfxDocument;
use App\Dto\Procurement\Prepare\Gsfx\GsfxPosition;
use App\Dto\Procurement\Prepare\Gsfx\GsfxResource;
use App\Dto\Procurement\Prepare\Gsfx\GsfxTzm2022;
use App\Dto\Procurement\Prepare\Gsfx\GsfxWorkType;
use App\Enums\Procurement\Estimate\ResourceType;
use App\Enums\Procurement\Estimate\RowType;
use App\Enums\Procurement\Estimate\TotalKind;
use App\Services\Procurement\Analyzers\Estimate\Core\DecimalNormalizer;

final readonly class GsfxGoldRowExtractor
{
    private const int MONEY_SCALE = 2;

    private const int INTERMEDIATE_SCALE = 10;

    /**
     * @param  array<string, GsfxWorkType>  $workTypeIndex
     * @return list<EstimateRow>
     */
    public function extractRows(GsfxDocument $document, array $workTypeIndex): array
    {
        $rows = [];
        $rowIndex = 0;

        foreach ($document->chapters as $chapter) {
            $rowIndex++;
            $rows[] = new EstimateRow(
                row_index: $rowIndex,
                row_type: RowType::SectionHeader,
                name: $chapter->caption,
            );

            foreach ($chapter->positions as $position) {
                $rowIndex++;
                $rows[] = $this->mapPositionRow($rowIndex, $position, $document, $workTypeIndex);
            }
        }

        return $rows;
    }

    public function mapTotals(GsfxDocument $document): EstimateTotalsBlock
    {
        $footer = [];

        foreach ($document->add_zatr_glavas as $glava) {
            if ($glava->items === []) {
                continue;
            }

            $chapterNumber = $this->resolveChapterNumber($glava);

            foreach ($glava->items as $addZatr) {
                $footer[] = $this->mapAddZatr($addZatr, $chapterNumber);
            }
        }

        if ($document->passport->total !== null) {
            $footer[] = new TotalLine(
                label: 'Итого по смете',
                value: $this->normalizeDecimal($document->passport->total),
                kind: TotalKind::Total,
            );
        }

        return new EstimateTotalsBlock(footer: $footer);
    }

    /** @return numeric-string|null */
    public function normalizeDecimal(?string $raw): ?string
    {
        return DecimalNormalizer::normalize($raw, allowLeadingDot: true, maxExponent: 50);
    }

    /**
     * @param  array<string, GsfxWorkType>  $workTypeIndex
     */
    private function mapPositionRow(int $rowIndex, GsfxPosition $position, GsfxDocument $document, array $workTypeIndex): EstimateRow
    {
        $resources = [];

        foreach ($position->resources as $resource) {
            $resources[] = $this->mapResource($resource, $document);

            if ($resource->tzm2022 !== null) {
                $resources[] = $this->mapTzm2022Resource($resource, $resource->tzm2022);
            }
        }

        [$totalPriceBase, $totalPriceCurr] = $this->resolvePositionPrice($position);

        $modifiers = array_map($this->mapModifier(...), $position->coefficients);
        $workType = $position->work_type_id !== null ? ($workTypeIndex[$position->work_type_id] ?? null) : null;

        return new EstimateRow(
            row_index: $rowIndex,
            row_type: RowType::Item,
            name: $position->name,
            code: $position->code,
            unit: $position->unit,
            quantity: $this->normalizeDecimal($position->quantity),
            unit_price_base: null,
            unit_price_curr: null,
            total_price_base: $totalPriceBase,
            total_price_curr: $totalPriceCurr,
            index_code: $position->index_code,
            index_value: $this->resolvePositionIndexValue($position, $document),
            source_position_number: $position->number,
            work_type_name: $workType?->caption,
            resources: $resources,
            attributes: $modifiers === [] ? null : new RowAttributes(modifiers: $modifiers),
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolvePositionPrice(GsfxPosition $position): array
    {
        if ($position->price_base !== null || $position->price_curr !== null) {
            return [
                $this->normalizeDecimal($position->price_base),
                $this->normalizeDecimal($position->price_curr),
            ];
        }

        if ($position->resources === []) {
            return [null, null];
        }

        $sum = null;

        foreach ($position->resources as $resource) {
            if ($resource->resource_type === 'Work') {
                continue;
            }

            $quantity = $this->normalizeDecimal($resource->quantity);
            $price = $this->normalizeDecimal($resource->price_curr);

            if ($quantity === null || $price === null) {
                continue;
            }

            $sum = bcadd($sum ?? '0', bcmul($quantity, $price, self::INTERMEDIATE_SCALE), self::INTERMEDIATE_SCALE);
        }

        return [null, $sum === null ? null : bcadd($sum, '0', self::MONEY_SCALE)];
    }

    private function resolvePositionIndexValue(GsfxPosition $position, GsfxDocument $document): ?string
    {
        if ($position->index_code === null || $document->index_catalog === null) {
            return null;
        }

        $entry = $document->index_catalog->positions[$position->index_code] ?? null;

        return $entry?->mt !== null ? $this->normalizeDecimal($entry->mt) : null;
    }

    private function mapResource(GsfxResource $resource, GsfxDocument $document): RowResource
    {
        return new RowResource(
            resource_type: $this->resolveResourceType($resource->resource_type),
            name: $resource->name,
            code: $resource->code,
            unit: $resource->unit,
            quantity: $this->normalizeDecimal($resource->quantity),
            price_base: $this->normalizeDecimal($resource->price_base),
            price_curr: $this->normalizeDecimal($resource->price_curr),
            index_code: $resource->index_code,
            index_value: $this->resolveResourceIndexValue($resource, $document),
        );
    }

    private function resolveResourceIndexValue(GsfxResource $resource, GsfxDocument $document): ?string
    {
        if ($resource->index_code === null || $document->index_catalog === null) {
            return null;
        }

        $entry = $document->index_catalog->resources[$resource->index_code] ?? null;

        if ($entry === null) {
            return null;
        }

        $value = $resource->resource_type === 'Mat' ? $entry->mt : $entry->em;

        return $this->normalizeDecimal($value);
    }

    private function resolveResourceType(string $rawType): ResourceType
    {
        return match ($rawType) {
            'Mat' => ResourceType::Material,
            'Mch' => ResourceType::Machine,
            'Tzr', 'Tzm' => ResourceType::Labor,
            default => ResourceType::Other,
        };
    }

    private function mapTzm2022Resource(GsfxResource $parent, GsfxTzm2022 $tzm2022): RowResource
    {
        return new RowResource(
            resource_type: ResourceType::Labor,
            name: $parent->name,
            code: null,
            unit: null,
            quantity: $this->normalizeDecimal($tzm2022->quantity),
            price_base: null,
            price_curr: $this->normalizeDecimal($tzm2022->price),
            index_code: null,
            index_value: null,
        );
    }

    private function mapModifier(GsfxCoefficient $coefficient): RowModifier
    {
        return new RowModifier(
            name: $coefficient->caption,
            kind: null,
            code: $coefficient->code,
            value: null,
            value_labor: $this->normalizeDecimal($coefficient->value_oz),
            value_machines: $this->normalizeDecimal($coefficient->value_em),
            value_machinists_wage: $this->normalizeDecimal($coefficient->value_zm),
            value_materials: $this->normalizeDecimal($coefficient->value_mt),
            level: $coefficient->level !== null ? (int) $coefficient->level : null,
        );
    }

    private function resolveChapterNumber(GsfxAddZatrGlava $glava): ?int
    {
        return $glava->glava !== null && ctype_digit($glava->glava) ? (int) $glava->glava : null;
    }

    private function mapAddZatr(GsfxAddZatr $addZatr, ?int $chapterNumber): TotalLine
    {
        $isPercent = $addZatr->options !== null && str_contains($addZatr->options, 'Percent');
        $normalizedValue = $this->normalizeDecimal($addZatr->value);

        return new TotalLine(
            label: $addZatr->caption,
            value: $isPercent ? null : $normalizedValue,
            kind: null,
            chapter_number: $chapterNumber,
            rate: $isPercent ? $normalizedValue : null,
        );
    }
}
