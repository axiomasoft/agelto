<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Gold;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowResource;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Read-only представление gold-файла. Строки и паспорт намеренно используют контрактные DTO,
 * чтобы у формы §8.2 не появился второй носитель.
 *
 * @param  list<array{passport: PassportResponse, rows: list<EstimateRow>}>  $estimates
 */
final readonly class GoldDocumentView
{
    public function __construct(public array $estimates) {}

    /**
     * @param  array<string, mixed>  $gold
     *
     * @throws ValidationException|CannotCreateData
     */
    public static function fromPayload(array $gold): self
    {
        $estimates = array_map(static function (mixed $estimate): array {
            $estimate = (array) $estimate;

            return [
                'passport' => PassportResponse::validateAndCreate(Arr::except($estimate, ['rows'])),
                'rows' => array_map(
                    static fn (mixed $row): EstimateRow => EstimateRow::validateAndCreate((array) $row),
                    array_values((array) ($estimate['rows'] ?? [])),
                ),
            ];
        }, array_values((array) ($gold['estimates'] ?? [])));

        return new self($estimates);
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $records = [];

        foreach ($this->estimates as $estimateIndex => $gold) {
            foreach ($gold['rows'] as $row) {
                $records[] = [
                    'id' => "{$estimateIndex}-{$row->row_index}",
                    'row_index' => $row->row_index,
                    'row_type' => $row->row_type->value,
                    'section_title' => $row->section_title,
                    'code' => $row->code,
                    'name' => $row->name,
                    'unit' => $row->unit,
                    'quantity' => $row->quantity,
                    'unit_price_base' => $row->unit_price_base,
                    'unit_price_curr' => $row->unit_price_curr,
                    'total_price_base' => $row->total_price_base,
                    'total_price_curr' => $row->total_price_curr,
                    'resources' => array_map(static fn (RowResource $resource): string => trim(implode(' · ', array_filter([
                        $resource->resource_type->value,
                        $resource->code,
                        $resource->name,
                        $resource->unit,
                        $resource->quantity,
                    ]))), $row->resources),
                ];
            }
        }

        return $records;
    }

    /** @return list<array{number: string|null, fields: array<string, mixed>, totals: list<array{label: string, value: string|null, rate: string|null}>}> */
    public function summaries(): array
    {
        return array_map(static function (array $gold): array {
            $passport = $gold['passport'];
            $totals = $passport->totals;

            return [
                'number' => $passport->estimate_number,
                'fields' => [
                    'estimate_number' => $passport->estimate_number,
                    'currency' => $passport->currency,
                    'base_year' => $passport->base_year,
                    'price_level' => $passport->price_level,
                    'regulatory_base' => $passport->regulatory_base,
                    'rows_expected' => $passport->rows_expected,
                    'calc_method' => $passport->calc_method?->value,
                ],
                'totals' => $totals === null ? [] : array_map(static fn ($total): array => [
                    'label' => $total->label,
                    'value' => $total->value,
                    'rate' => $total->rate,
                ], $totals->footer),
            ];
        }, $this->estimates);
    }
}
